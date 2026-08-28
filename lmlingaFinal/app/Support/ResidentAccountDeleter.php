<?php

namespace App\Support;

use App\Models\ResidentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deletes a chatbot ResidentAccount and chatbot-owned dependents.
 *
 * Live MySQL foreign keys that block a bare $account->delete() are
 * ON DELETE RESTRICT (none CASCADE). Official residents/households
 * are not dependents of resident_accounts; resident_id is SET NULL
 * only when an official resident is deleted, which this class never does.
 */
final class ResidentAccountDeleter
{
    public function delete(ResidentAccount $account): void
    {
        $accountId = (int) $account->account_id;

        DB::transaction(function () use ($accountId): void {
            ResidentAccount::query()
                ->whereKey($accountId)
                ->lockForUpdate()
                ->firstOrFail();

            $requestIds = DB::table('record_requests')
                ->where('account_id', $accountId)
                ->pluck('request_id');

            $conversationIds = collect();
            if (Schema::hasTable('chatbot_conversations')) {
                $conversationIds = DB::table('chatbot_conversations')
                    ->where('account_id', $accountId)
                    ->pluck('conversation_id');
            }

            // fk_notif_account, fk_notif_request, fk_notif_conv: RESTRICT
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->where('account_id', $accountId)->delete();
            }

            // fk_chatmsg_conv: RESTRICT
            if (Schema::hasTable('chatbot_messages') && $conversationIds->isNotEmpty()) {
                DB::table('chatbot_messages')->whereIn('conversation_id', $conversationIds)->delete();
            }

            // fk_chatconv_account: RESTRICT
            if (Schema::hasTable('chatbot_conversations')) {
                DB::table('chatbot_conversations')->where('account_id', $accountId)->delete();
            }

            // record_request_otps_request_id_foreign: RESTRICT
            if (Schema::hasTable('record_request_otps') && $requestIds->isNotEmpty()) {
                DB::table('record_request_otps')->whereIn('request_id', $requestIds)->delete();
            }

            // fk_recreq_account: RESTRICT
            DB::table('record_requests')->where('account_id', $accountId)->delete();

            // fk_residentreset_account: RESTRICT
            if (Schema::hasTable('resident_password_resets')) {
                DB::table('resident_password_resets')->where('account_id', $accountId)->delete();
            }

            ResidentAccount::query()->whereKey($accountId)->delete();
        });
    }
}
