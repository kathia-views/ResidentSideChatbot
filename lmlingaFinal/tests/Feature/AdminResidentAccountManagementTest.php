<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentPasswordResetToken;
use App\Support\ResidentAccountDeleter;
use App\Support\ResidentAccountUiCatalog;
use App\Support\UiRole;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminResidentAccountManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD_PLAIN = 'ValidPass!123';

    /**
     * @return array<string, mixed>
     */
    private function accountAttributes(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '1',
            'email' => 'ana.chatbot@example.com',
            'password' => Hash::make(self::PASSWORD_PLAIN),
            'resident_id' => null,
        ], $overrides);
    }

    private function asAdmin(): self
    {
        return $this->withSession([UiRole::SESSION_KEY => 'admin']);
    }

    private function publicId(ResidentAccount $account): string
    {
        return ResidentAccountUiCatalog::publicId((int) $account->account_id);
    }

    public function test_residents_tab_lists_real_resident_account_not_demo_rows(): void
    {
        ResidentAccount::query()->create($this->accountAttributes());

        $html = $this->asAdmin()
            ->get(route('user-management.index', ['tab' => 'residents']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ana.chatbot@example.com', $html);
        $this->assertStringContainsString('Ana Cruz Santos', $html);
        $this->assertStringContainsString('Zone 1', $html);
        $this->assertStringNotContainsString('kristine.reyes@email.com', $html);
        $this->assertStringNotContainsString('melanie.javier@email.com', $html);
        $this->assertStringNotContainsString('Kristine Mendoza Reyes', $html);
    }

    public function test_official_resident_without_chatbot_account_is_not_listed(): void
    {
        Resident::factory()->create([
            'first_name' => 'OfficialNoChatbot',
            'last_name' => 'OnlyResident',
        ]);

        ResidentAccount::query()->create($this->accountAttributes());

        $html = $this->asAdmin()
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('ana.chatbot@example.com', $html);
        $this->assertStringNotContainsString('OfficialNoChatbot', $html);
        $this->assertStringNotContainsString('OnlyResident', $html);
    }

    public function test_view_loads_the_correct_resident_account(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'view.me@example.com',
            'first_name' => 'Viewed',
            'last_name' => 'Account',
        ]));

        $html = $this->asAdmin()
            ->get(route('user-management.residents.view', ['id' => $this->publicId($account)]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('view.me@example.com', $html);
        $this->assertStringContainsString('Viewed Cruz Account', $html);
        $this->assertStringContainsString('Zone 1', $html);
    }

    public function test_padded_ra_public_id_resolves_to_account_id(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'padded.id@example.com',
        ]));

        $padded = 'ra-'.str_pad((string) $account->account_id, 3, '0', STR_PAD_LEFT);

        $this->asAdmin()
            ->get(route('user-management.residents.view', ['id' => $padded]))
            ->assertOk()
            ->assertSee('padded.id@example.com');
    }

    public function test_missing_resident_account_returns_404(): void
    {
        $this->asAdmin()
            ->get(route('user-management.residents.view', ['id' => 'ra-999']))
            ->assertNotFound();

        $this->asAdmin()
            ->get(route('user-management.residents.edit', ['id' => 'ra-999']))
            ->assertNotFound();

        $this->asAdmin()
            ->delete(route('user-management.residents.destroy', ['id' => 'ra-999']))
            ->assertNotFound();
    }

    public function test_edit_updates_resident_account_only(): void
    {
        $resident = Resident::factory()->create([
            'first_name' => 'OfficialFirst',
            'last_name' => 'OfficialLast',
        ]);

        $account = ResidentAccount::query()->create($this->accountAttributes([
            'resident_id' => $resident->getAttribute(Resident::resolvedPrimaryKeyName()),
            'email' => 'before.update@example.com',
        ]));

        $this->asAdmin()
            ->from(route('user-management.residents.edit', ['id' => $this->publicId($account)]))
            ->put(route('user-management.residents.update', ['id' => $this->publicId($account)]), [
                'first_name' => 'Updated',
                'middle_name' => 'Mid',
                'last_name' => 'Name',
                'zone' => 'Zone 4',
                'email' => 'after.update@example.com',
            ])
            ->assertRedirect(route('user-management.residents.view', ['id' => $this->publicId($account)]));

        $account->refresh();
        $resident->refresh();

        $this->assertSame('Updated', $account->first_name);
        $this->assertSame('Mid', $account->middle_name);
        $this->assertSame('Name', $account->last_name);
        $this->assertSame('4', $account->zone_purok);
        $this->assertSame('after.update@example.com', $account->email);
        $this->assertSame('OfficialFirst', $resident->first_name);
        $this->assertSame('OfficialLast', $resident->last_name);
    }

    public function test_email_uniqueness_is_validated_on_update(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'keep.me@example.com',
        ]));
        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'taken@example.com',
            'first_name' => 'Other',
        ]));

        $this->asAdmin()
            ->from(route('user-management.residents.edit', ['id' => $this->publicId($account)]))
            ->put(route('user-management.residents.update', ['id' => $this->publicId($account)]), [
                'first_name' => 'Ana',
                'middle_name' => 'Cruz',
                'last_name' => 'Santos',
                'zone' => 'Zone 1',
                'email' => 'taken@example.com',
            ])
            ->assertRedirect(route('user-management.residents.edit', ['id' => $this->publicId($account)]))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $account->account_id,
            'email' => 'keep.me@example.com',
        ]);
    }

    public function test_delete_removes_account_but_keeps_official_resident(): void
    {
        $resident = Resident::factory()->create([
            'first_name' => 'KeepOfficial',
            'last_name' => 'Resident',
        ]);

        $account = ResidentAccount::query()->create($this->accountAttributes([
            'resident_id' => $resident->getAttribute(Resident::resolvedPrimaryKeyName()),
            'email' => 'delete.me@example.com',
        ]));

        $this->asAdmin()
            ->delete(route('user-management.residents.destroy', ['id' => $this->publicId($account)]))
            ->assertRedirect(route('user-management.index', ['tab' => 'residents']))
            ->assertSessionHas('status', 'Resident account deleted successfully.');

        $this->assertDatabaseMissing('resident_accounts', [
            'email' => 'delete.me@example.com',
        ]);
        $this->assertDatabaseHas('residents', [
            'first_name' => 'KeepOfficial',
            'last_name' => 'Resident',
        ]);
        $this->assertNotNull(Resident::query()->find($resident->getKey()));
    }

    public function test_unlinked_resident_account_can_be_listed_viewed_edited_and_deleted(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'unlinked@example.com',
            'resident_id' => null,
            'zone_purok' => '5',
        ]));
        $id = $this->publicId($account);

        $this->asAdmin()
            ->get(route('user-management.index'))
            ->assertOk()
            ->assertSee('unlinked@example.com');

        $this->asAdmin()
            ->get(route('user-management.residents.view', ['id' => $id]))
            ->assertOk()
            ->assertSee('Zone 5');

        $this->asAdmin()
            ->put(route('user-management.residents.update', ['id' => $id]), [
                'first_name' => 'Unlinked',
                'middle_name' => null,
                'last_name' => 'User',
                'zone' => 'Zone 2',
                'email' => 'unlinked.updated@example.com',
            ])
            ->assertRedirect(route('user-management.residents.view', ['id' => $id]));

        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $account->account_id,
            'email' => 'unlinked.updated@example.com',
            'resident_id' => null,
            'zone_purok' => '2',
        ]);

        $this->asAdmin()
            ->delete(route('user-management.residents.destroy', ['id' => $id]))
            ->assertRedirect(route('user-management.index', ['tab' => 'residents']))
            ->assertSessionHas('status', 'Resident account deleted successfully.');

        $this->assertDatabaseMissing('resident_accounts', [
            'account_id' => $account->account_id,
        ]);
    }

    public function test_password_hash_is_not_exposed_on_admin_pages(): void
    {
        $hash = Hash::make(self::PASSWORD_PLAIN);
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'password' => $hash,
            'email' => 'secret.hash@example.com',
        ]));
        $id = $this->publicId($account);

        $index = $this->asAdmin()->get(route('user-management.index'))->assertOk()->getContent();
        $view = $this->asAdmin()->get(route('user-management.residents.view', ['id' => $id]))->assertOk()->getContent();
        $edit = $this->asAdmin()->get(route('user-management.residents.edit', ['id' => $id]))->assertOk()->getContent();

        foreach ([$index, $view, $edit] as $html) {
            $this->assertStringNotContainsString($hash, $html);
            $this->assertStringNotContainsString(self::PASSWORD_PLAIN, $html);
            $this->assertStringNotContainsString('name="password"', $html);
        }
    }

    public function test_linked_account_displays_zone_purok_without_loading_official_resident(): void
    {
        $resident = Resident::factory()->create();
        $linkedId = $resident->getAttribute(Resident::resolvedPrimaryKeyName());
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'zone_purok' => '1',
            'resident_id' => $linkedId,
            'email' => 'zone.map@example.com',
        ]));

        $index = $this->asAdmin()
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $view = $this->asAdmin()
            ->get(route('user-management.residents.view', ['id' => $this->publicId($account)]))
            ->assertOk()
            ->getContent();

        foreach ([$index, $view] as $html) {
            $this->assertStringContainsString('Zone 1', $html);
            $this->assertStringContainsString('Ana Cruz Santos', $html);
            $this->assertStringContainsString('zone.map@example.com', $html);
        }

        $this->assertSame($linkedId, $account->fresh()->resident_id);
        $this->assertSame(1, Resident::query()->count());
    }

    public function test_delete_cleans_owned_requests_and_otps_without_touching_other_accounts_or_masterlist(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-KEEP']);
        $official = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'KeepOfficial',
            'last_name' => 'Member',
        ]);

        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.delete@example.com',
            'resident_id' => $official->getAttribute(Resident::resolvedPrimaryKeyName()),
        ]));
        $other = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'other.keep@example.com',
            'first_name' => 'Other',
            'last_name' => 'Keep',
            'zone_purok' => '3',
        ]));

        $ownedRequest = $this->seedRecordRequest($owner, RecordRequest::STATUS_PENDING);
        $otherRequest = $this->seedRecordRequest($other, RecordRequest::STATUS_DENIED);

        $ownedOtp = $this->seedOtp($ownedRequest);
        $otherOtp = $this->seedOtp($otherRequest);

        $reset = new ResidentPasswordResetToken;
        $reset->account_id = $owner->account_id;
        $reset->reset_token = 'owned-reset-token';
        $reset->requested_at = now();
        $reset->expires_at = now()->addHour();
        $reset->is_used = false;
        $reset->created_at = now();
        $reset->save();

        $otherReset = new ResidentPasswordResetToken;
        $otherReset->account_id = $other->account_id;
        $otherReset->reset_token = 'other-reset-token';
        $otherReset->requested_at = now();
        $otherReset->expires_at = now()->addHour();
        $otherReset->is_used = false;
        $otherReset->created_at = now();
        $otherReset->save();

        $this->asAdmin()
            ->delete(route('user-management.residents.destroy', ['id' => $this->publicId($owner)]))
            ->assertRedirect(route('user-management.index', ['tab' => 'residents']))
            ->assertSessionHas('status', 'Resident account deleted successfully.');

        $this->assertDatabaseMissing('resident_accounts', [
            'account_id' => $owner->account_id,
        ]);
        $this->assertDatabaseMissing('record_requests', [
            'request_id' => $ownedRequest->request_id,
        ]);
        $this->assertDatabaseMissing('record_request_otps', [
            'otp_id' => $ownedOtp->otp_id,
        ]);
        $this->assertDatabaseMissing('resident_password_resets', [
            'reset_id' => $reset->reset_id,
        ]);

        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $other->account_id,
            'email' => 'other.keep@example.com',
        ]);
        $this->assertDatabaseHas('record_requests', [
            'request_id' => $otherRequest->request_id,
            'account_id' => $other->account_id,
        ]);
        $this->assertDatabaseHas('record_request_otps', [
            'otp_id' => $otherOtp->otp_id,
            'request_id' => $otherRequest->request_id,
        ]);
        $this->assertDatabaseHas('resident_password_resets', [
            'reset_id' => $otherReset->reset_id,
            'account_id' => $other->account_id,
        ]);

        $this->assertNotNull($official->fresh());
        $this->assertNotNull($household->fresh());
        $this->assertSame('HH-KEEP', $household->fresh()->household_no);
    }

    public function test_delete_cleans_owned_conversations_messages_and_notifications_without_touching_other_accounts(): void
    {
        $this->ensureChatbotSideTables();

        $household = Household::factory()->create(['household_no' => 'HH-CHAT']);
        $official = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'KeepChatOfficial',
            'last_name' => 'Member',
        ]);

        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.chat@example.com',
            'resident_id' => $official->getAttribute(Resident::resolvedPrimaryKeyName()),
        ]));
        $other = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'other.chat@example.com',
            'first_name' => 'Other',
            'last_name' => 'Chat',
            'zone_purok' => '3',
        ]));

        $ownedRequest = $this->seedRecordRequest($owner, RecordRequest::STATUS_PENDING);
        $otherRequest = $this->seedRecordRequest($other, RecordRequest::STATUS_DENIED);
        $ownedOtp = $this->seedOtp($ownedRequest);
        $otherOtp = $this->seedOtp($otherRequest);

        $ownedConversationId = $this->seedConversation($owner->account_id, 'Owner thread');
        $otherConversationId = $this->seedConversation($other->account_id, 'Other thread');
        $ownedMessageId = $this->seedMessage($ownedConversationId, 'Owner message');
        $otherMessageId = $this->seedMessage($otherConversationId, 'Other message');

        $ownedDirectNotificationId = $this->seedNotification($owner->account_id, 'Owner system', null, null);
        $ownedRequestNotificationId = $this->seedNotification(
            $owner->account_id,
            'Owner request notice',
            $ownedRequest->request_id,
            null
        );
        $ownedConversationNotificationId = $this->seedNotification(
            $owner->account_id,
            'Owner chat notice',
            null,
            $ownedConversationId
        );
        $otherNotificationId = $this->seedNotification(
            $other->account_id,
            'Other notice',
            $otherRequest->request_id,
            $otherConversationId
        );

        $this->asAdmin()
            ->delete(route('user-management.residents.destroy', ['id' => $this->publicId($owner)]))
            ->assertRedirect(route('user-management.index', ['tab' => 'residents']))
            ->assertSessionHas('status', 'Resident account deleted successfully.');

        $this->assertDatabaseMissing('resident_accounts', ['account_id' => $owner->account_id]);
        $this->assertDatabaseMissing('record_requests', ['request_id' => $ownedRequest->request_id]);
        $this->assertDatabaseMissing('record_request_otps', ['otp_id' => $ownedOtp->otp_id]);
        $this->assertDatabaseMissing('chatbot_conversations', ['conversation_id' => $ownedConversationId]);
        $this->assertDatabaseMissing('chatbot_messages', ['message_id' => $ownedMessageId]);
        $this->assertDatabaseMissing('notifications', ['notification_id' => $ownedDirectNotificationId]);
        $this->assertDatabaseMissing('notifications', ['notification_id' => $ownedRequestNotificationId]);
        $this->assertDatabaseMissing('notifications', ['notification_id' => $ownedConversationNotificationId]);

        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $other->account_id,
            'email' => 'other.chat@example.com',
        ]);
        $this->assertDatabaseHas('record_requests', [
            'request_id' => $otherRequest->request_id,
            'account_id' => $other->account_id,
        ]);
        $this->assertDatabaseHas('record_request_otps', [
            'otp_id' => $otherOtp->otp_id,
            'request_id' => $otherRequest->request_id,
        ]);
        $this->assertDatabaseHas('chatbot_conversations', [
            'conversation_id' => $otherConversationId,
            'account_id' => $other->account_id,
        ]);
        $this->assertDatabaseHas('chatbot_messages', [
            'message_id' => $otherMessageId,
            'conversation_id' => $otherConversationId,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notification_id' => $otherNotificationId,
            'account_id' => $other->account_id,
        ]);

        $this->assertNotNull($official->fresh());
        $this->assertNotNull($household->fresh());
        $this->assertSame('HH-CHAT', $household->fresh()->household_no);
    }

    public function test_delete_rolls_back_when_a_restrict_dependency_cannot_be_removed(): void
    {
        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'rollback.owner@example.com',
        ]));
        $other = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'rollback.other@example.com',
            'first_name' => 'Other',
        ]));

        $ownedRequest = $this->seedRecordRequest($owner, RecordRequest::STATUS_PENDING);
        $ownedOtp = $this->seedOtp($ownedRequest);
        $otherRequest = $this->seedRecordRequest($other, RecordRequest::STATUS_DENIED);

        Schema::create('tmp_account_delete_blockers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->foreign('account_id')
                ->references('account_id')
                ->on('resident_accounts')
                ->restrictOnDelete();
        });
        DB::table('tmp_account_delete_blockers')->insert([
            'account_id' => $owner->account_id,
        ]);

        try {
            app(ResidentAccountDeleter::class)->delete($owner);
            $this->fail('Expected QueryException when a RESTRICT child still references the account.');
        } catch (QueryException) {
            // Transaction must roll back all chatbot-owned cleanup.
        }

        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $owner->account_id,
            'email' => 'rollback.owner@example.com',
        ]);
        $this->assertDatabaseHas('record_requests', [
            'request_id' => $ownedRequest->request_id,
            'account_id' => $owner->account_id,
        ]);
        $this->assertDatabaseHas('record_request_otps', [
            'otp_id' => $ownedOtp->otp_id,
            'request_id' => $ownedRequest->request_id,
        ]);
        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $other->account_id,
        ]);
        $this->assertDatabaseHas('record_requests', [
            'request_id' => $otherRequest->request_id,
            'account_id' => $other->account_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedRecordRequest(ResidentAccount $account, string $status, array $overrides = []): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = $overrides['household_no_submitted'] ?? 'HH-001';
        $row->zone_submitted = '1';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '203.0.113.70';
        $row->matched_resident_id = $overrides['matched_resident_id'] ?? null;
        $row->status = $status;
        $row->decision_reason = null;
        $row->evaluated_at = null;
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    private function seedOtp(RecordRequest $request): RecordRequestOtp
    {
        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = 'admin-delete-placeholder-hash';
        $otp->destination_fingerprint = 'admin-delete-placeholder-fingerprint';
        $otp->expires_at = now()->addMinutes(5);
        $otp->attempt_count = 0;
        $otp->resend_count = 0;
        $otp->save();

        return $otp->fresh();
    }

    private function ensureChatbotSideTables(): void
    {
        if (! Schema::hasTable('chatbot_conversations')) {
            Schema::create('chatbot_conversations', function (Blueprint $table): void {
                $table->id('conversation_id');
                $table->unsignedBigInteger('account_id');
                $table->string('title', 150)->nullable();
                $table->boolean('is_pinned')->default(false);
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->foreign('account_id')
                    ->references('account_id')
                    ->on('resident_accounts')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('chatbot_messages')) {
            Schema::create('chatbot_messages', function (Blueprint $table): void {
                $table->id('message_id');
                $table->unsignedBigInteger('conversation_id');
                $table->string('sender', 20);
                $table->text('message_text');
                $table->timestamp('sent_at');
                $table->timestamp('created_at')->nullable();

                $table->foreign('conversation_id')
                    ->references('conversation_id')
                    ->on('chatbot_conversations')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->id('notification_id');
                $table->unsignedBigInteger('account_id');
                $table->string('notification_type', 40);
                $table->string('title', 150);
                $table->text('message')->nullable();
                $table->unsignedBigInteger('related_request_id')->nullable();
                $table->unsignedBigInteger('related_conversation_id')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('created_at')->nullable();

                $table->foreign('account_id')
                    ->references('account_id')
                    ->on('resident_accounts')
                    ->restrictOnDelete();
                $table->foreign('related_request_id')
                    ->references('request_id')
                    ->on('record_requests')
                    ->restrictOnDelete();
                $table->foreign('related_conversation_id')
                    ->references('conversation_id')
                    ->on('chatbot_conversations')
                    ->restrictOnDelete();
            });
        }
    }

    private function seedConversation(int $accountId, string $title): int
    {
        return (int) DB::table('chatbot_conversations')->insertGetId([
            'account_id' => $accountId,
            'title' => $title,
            'is_pinned' => 0,
            'last_message_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], 'conversation_id');
    }

    private function seedMessage(int $conversationId, string $text): int
    {
        return (int) DB::table('chatbot_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender' => 'Resident',
            'message_text' => $text,
            'sent_at' => now(),
            'created_at' => now(),
        ], 'message_id');
    }

    /**
     * @return int
     */
    private function seedNotification(
        int $accountId,
        string $title,
        ?int $relatedRequestId,
        ?int $relatedConversationId
    ): int {
        return (int) DB::table('notifications')->insertGetId([
            'account_id' => $accountId,
            'notification_type' => 'System',
            'title' => $title,
            'message' => $title,
            'related_request_id' => $relatedRequestId,
            'related_conversation_id' => $relatedConversationId,
            'is_read' => 0,
            'created_at' => now(),
        ], 'notification_id');
    }

    public function test_bhw_cannot_manage_resident_accounts(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('user-management.index'))
            ->assertForbidden();

        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('user-management.residents.view', ['id' => $this->publicId($account)]))
            ->assertForbidden();
    }

    public function test_chatbot_register_and_login_still_work(): void
    {
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);

        $this->from(route('chatbot.register'))->post(route('chatbot.register.store'), [
            'first_name' => 'Liza',
            'middle_name' => 'Mae',
            'last_name' => 'Reyes',
            'zone' => '2',
            'email' => 'liza.admin-regression@example.com',
            'password' => self::PASSWORD_PLAIN,
        ])->assertRedirect(route('chatbot.login'));

        $this->from(route('chatbot.login'))->post(route('chatbot.login.store'), [
            'email' => 'liza.admin-regression@example.com',
            'password' => self::PASSWORD_PLAIN,
        ])->assertRedirect(route('chatbot.main'))
            ->assertSessionHas('resident_account_id');
    }
}
