<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Support\ChatbotHouseholdNumberDisplay;
use App\Support\HouseholdRecordVerifiedAccess;
use Illuminate\View\View;

class ChatbotMainController extends Controller
{
    public function show(): View
    {
        $account = $this->currentResidentAccount();

        return view('pages.chatbot.main', [
            'residentDisplayName' => $this->displayName($account),
            'householdDisplayNo' => $this->officialHouseholdDisplay($account),
            'householdRequestState' => $this->householdRequestState($account),
            'householdDecisionReason' => $this->householdDecisionReason($account),
        ]);
    }

    private function currentResidentAccount(): ?ResidentAccount
    {
        $accountId = session('resident_account_id');

        if ($accountId === null || $accountId === '') {
            return null;
        }

        $account = ResidentAccount::query()->find($accountId);

        return $account instanceof ResidentAccount ? $account : null;
    }

    private function displayName(?ResidentAccount $account): string
    {
        if (! $account instanceof ResidentAccount) {
            return 'Resident';
        }

        $first = trim((string) $account->first_name);
        $middle = trim((string) $account->middle_name);
        $last = trim((string) $account->last_name);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        return $name !== '' ? $name : 'Resident';
    }

    /**
     * Official households.household_no for the session account's linked resident.
     * Identity display only — independent of RecordRequest access/CTA state.
     * Resolved server-side only — never from browser/query identifiers.
     */
    private function officialHouseholdDisplay(?ResidentAccount $account): string
    {
        if (! $account instanceof ResidentAccount) {
            return '-';
        }

        $householdNo = HouseholdRecordVerifiedAccess::officialHouseholdNoForLinkedAccount($account);

        return $householdNo !== null
            ? ChatbotHouseholdNumberDisplay::format($householdNo)
            : '-';
    }

    private function householdRequestState(?ResidentAccount $account): string
    {
        if (! $account instanceof ResidentAccount) {
            return 'none';
        }

        $record = RecordRequest::latestForAccount($account->account_id);

        if (! $record instanceof RecordRequest || (int) $record->account_id !== (int) $account->account_id) {
            return 'none';
        }

        // Permanent verified CTA: Approved + OTP evidence + valid resident/household link.
        if (HouseholdRecordVerifiedAccess::grantsHouseholdInformationAccess($account, $record)) {
            return 'approved';
        }

        if (HouseholdRecordVerifiedAccess::requiresOtpVerification($record)) {
            return 'awaiting_otp';
        }

        return match ($record->status) {
            RecordRequest::STATUS_PENDING => 'pending',
            RecordRequest::STATUS_DENIED => 'denied',
            default => 'none',
        };
    }

    private function householdDecisionReason(?ResidentAccount $account): string
    {
        if (! $account instanceof ResidentAccount) {
            return '';
        }

        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            ! $record instanceof RecordRequest
            || (int) $record->account_id !== (int) $account->account_id
        ) {
            return '';
        }

        // Sidebar helper text is for Denied only. Verified residents must not see
        // stored match/OTP decision_reason (e.g. "Complete OTP verification…").
        if ($record->status === RecordRequest::STATUS_DENIED) {
            return trim((string) $record->decision_reason);
        }

        return '';
    }
}
