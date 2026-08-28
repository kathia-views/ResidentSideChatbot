<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChatbotMainHouseholdCtaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function accountAttributes(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '2',
            'email' => 'ana.main@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'householdNo' => 'HH-151',
            'relationship' => 'Household Head',
            'firstName' => 'Ana',
            'middleName' => 'Cruz',
            'lastName' => 'Santos',
            'mobileNumber' => '09171234567',
            'emailAddress' => 'ana.main@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedRequest(ResidentAccount $account, string $status, array $overrides = []): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = $overrides['household_no_submitted'] ?? 'HH-OLD';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Spouse';
        $row->first_name_submitted = 'Old';
        $row->middle_name_submitted = 'Name';
        $row->last_name_submitted = 'Row';
        $row->mobile_number_submitted = '09170000000';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '198.51.100.10';
        $row->matched_resident_id = $overrides['matched_resident_id'] ?? null;
        $row->status = $status;
        $row->decision_reason = $overrides['decision_reason'] ?? null;
        $row->evaluated_at = null;
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    private function assertRequestSentStatus(string $html): void
    {
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString('Request Household Record', $html);
        $this->assertStringNotContainsString(route('chatbot.household.verification.status'), $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString(route('chatbot.household.information'), $html);
        $this->assertStringNotContainsString('Continue Verification', $html);
        $this->assertStringNotContainsString('Request Could Not Be Verified', $html);
    }

    public function test_guest_main_shows_request_household_record_cta(): void
    {
        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $requestUrl = route('chatbot.household.verification');

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('href="'.e($requestUrl).'"', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
    }

    public function test_verification_query_does_not_fake_pending_or_access_cta_for_guest(): void
    {
        foreach (['verified', 'pending', 'unverified'] as $state) {
            $html = $this->get(route('chatbot.main', ['verification' => $state]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Request Household Record', $html);
            $this->assertStringContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);
            $this->assertStringNotContainsString('Access Household Record', $html);
            $this->assertStringNotContainsString('Request Sent', $html);
            $this->assertStringNotContainsString('Verification Pending', $html);
            $this->assertStringNotContainsString('Continue Verification', $html);
            $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);
            $this->assertStringContainsString('data-lml-verification-state="unverified"', $html);
        }
    }

    public function test_authenticated_resident_without_pending_sees_request_cta(): void
    {
        $account = $this->actingAsResidentAccount();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSame($account->resident_id, $account->fresh()->resident_id);
        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_authenticated_resident_with_pending_sees_request_sent_status(): void
    {
        $account = $this->actingAsResidentAccount();
        $row = $this->seedRequest($account, RecordRequest::STATUS_PENDING, [
            'household_no_submitted' => 'HH-151',
            'matched_resident_id' => null,
        ]);
        $updatedAt = $row->updated_at?->toJSON();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertRequestSentStatus($html);

        $fresh = $row->fresh();
        $this->assertSame('Pending', $fresh->status);
        $this->assertSame($updatedAt, $fresh->updated_at?->toJSON());
        $this->assertNull($fresh->matched_resident_id);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertDatabaseCount('record_requests', 1);
    }

    public function test_query_string_verified_cannot_override_real_pending_cta(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_PENDING);

        $html = $this->get(route('chatbot.main', ['verification' => 'verified']))
            ->assertOk()
            ->getContent();

        $this->assertRequestSentStatus($html);
    }

    public function test_query_string_pending_cannot_fake_pending_cta_without_db_row(): void
    {
        $this->actingAsResidentAccount();

        $html = $this->get(route('chatbot.main', ['verification' => 'pending']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_no_match_history_alone_shows_request_cta(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_NO_MATCH);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
    }

    public function test_authenticated_resident_with_awaiting_otp_sees_continue_verification(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_AWAITING_OTP, [
            'matched_resident_id' => 42,
        ]);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Continue Verification', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Request Could Not Be Verified', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, RecordRequest::query()->value('status'));
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_authenticated_resident_with_approved_without_otp_sees_continue_verification(): void
    {
        $account = $this->actingAsResidentAccount();
        $row = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => 42,
        ]);
        $updatedAt = $row->updated_at?->toJSON();
        $residentIdBefore = $account->resident_id;

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Continue Verification', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Request Could Not Be Verified', $html);

        $fresh = $row->fresh();
        $this->assertSame(RecordRequest::STATUS_APPROVED, $fresh->status);
        $this->assertSame($updatedAt, $fresh->updated_at?->toJSON());
        $this->assertSame(42, (int) $fresh->matched_resident_id);
        $this->assertSame($residentIdBefore, $account->fresh()->resident_id);
    }

    public function test_query_string_cannot_fake_approved_cta(): void
    {
        $this->actingAsResidentAccount();

        $html = $this->get(route('chatbot.main', [
            'verification' => 'Approved',
            'status' => 'Approved',
            'state' => 'access',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_viewing_main_does_not_write_record_requests_or_accounts(): void
    {
        $account = $this->actingAsResidentAccount();
        $row = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => 7,
        ]);
        $requestUpdated = $row->updated_at?->toJSON();
        $accountUpdated = $account->updated_at?->toJSON();

        $this->get(route('chatbot.main'))->assertOk();

        $this->assertSame($requestUpdated, $row->fresh()->updated_at?->toJSON());
        $this->assertSame($accountUpdated, $account->fresh()->updated_at?->toJSON());
        $this->assertSame(RecordRequest::STATUS_APPROVED, $row->fresh()->status);
        $this->assertDatabaseCount('record_request_otps', 0);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_query_string_cannot_fake_awaiting_otp_cta(): void
    {
        $this->actingAsResidentAccount();

        $html = $this->get(route('chatbot.main', [
            'verification' => 'Awaiting OTP',
            'status' => 'Awaiting OTP',
            'state' => 'sms',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringNotContainsString('Continue Verification', $html);
        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_denied_history_shows_rejection_and_safe_reason(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_DENIED, [
            'decision_reason' => 'The submitted household number could not be verified.',
            'matched_resident_id' => 99,
        ]);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Could Not Be Verified', $html);
        $this->assertStringContainsString('lml-chatbot-main__household-btn--denied', $html);
        $this->assertStringNotContainsString('lml-chatbot-main__household-btn--approved', $html);
        $this->assertStringContainsString('The submitted household number could not be verified.', $html);
        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Continue Verification', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('account_id', $html);
        $this->assertStringNotContainsString('resident_id', $html);
        $this->assertStringNotContainsString('household_id', $html);
        $this->assertStringNotContainsString('matched_resident_id', $html);
        $this->assertStringNotContainsString('request_id', $html);
        $this->assertSame(RecordRequest::STATUS_DENIED, RecordRequest::query()->value('status'));
        $this->assertNull(RecordRequest::query()->value('approved_at'));
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_another_account_pending_request_does_not_affect_current_cta(): void
    {
        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.main@example.com',
        ]));
        $this->seedRequest($owner, RecordRequest::STATUS_PENDING);

        $viewer = $this->actingAsResidentAccount([
            'email' => 'viewer.main@example.com',
        ]);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertSame(1, RecordRequest::query()->where('account_id', $owner->account_id)->count());
        $this->assertSame(0, RecordRequest::query()->where('account_id', $viewer->account_id)->count());
    }

    public function test_request_post_pending_then_main_shows_request_sent(): void
    {
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertDatabaseCount('record_requests', 1);
        $this->assertSame('Pending', RecordRequest::query()->value('status'));
        $this->assertNull(RecordRequest::query()->value('matched_resident_id'));
        $this->assertRequestSentStatus($html);
    }

    public function test_pending_account_cannot_open_request_form(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_PENDING);

        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_guest_cannot_open_request_household_record_page(): void
    {
        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_chatbot_login_register_and_forgot_password_pages_still_render(): void
    {
        $this->get(route('chatbot.login'))->assertOk();
        $this->get(route('chatbot.register'))->assertOk();
        $this->get(route('chatbot.password.request'))->assertOk();
    }
}
