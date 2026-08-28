<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdRecordRequestSmsInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.iprog.base_url', 'https://www.iprogsms.com/api/v1');
        Config::set('services.iprog.api_token', 'test-iprog-token');
        Http::preventStrayRequests();
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'Your SMS message has been successfully added to the queue and will be processed shortly.',
                'message_id' => 'iSms-SmsUi',
            ], 200),
        ]);
    }

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
            'email' => 'ana.sms@example.com',
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
            'householdNo' => 'HH-001',
            'relationship' => 'Household Head',
            'firstName' => 'Ana',
            'middleName' => 'Cruz',
            'lastName' => 'Santos',
            'mobileNumber' => '09171234567',
            'emailAddress' => 'ana.sms@example.com',
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
        $row->household_no_submitted = $overrides['household_no_submitted'] ?? 'HH-001';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = $overrides['mobile_number_submitted'] ?? '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '203.0.113.20';
        $row->matched_resident_id = $overrides['matched_resident_id'] ?? null;
        $row->status = $status;
        $row->decision_reason = $overrides['decision_reason'] ?? null;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    private function assertNoOtpOrSmsSideEffects(ResidentAccount $account): void
    {
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->assertFalse(Schema::hasTable('otps'));
        $this->assertFalse(Schema::hasTable('sms_messages'));
        $this->assertFalse(Schema::hasTable('verification_codes'));
        $columns = Schema::getColumnListing('record_requests');
        $this->assertSame([], array_values(array_filter(
            $columns,
            static fn (string $column): bool => str_contains(strtolower($column), 'otp')
        )));
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_guest_cannot_access_sms_otp_interface(): void
    {
        Mail::fake();
        Notification::fake();

        $this->get(route('chatbot.household.verification.sms'))
            ->assertRedirect(route('chatbot.login'));

        $this->get(route('chatbot.household.verification.sms', [
            'status' => 'Awaiting OTP',
            'verification' => 'sms',
            'request_id' => '1',
        ]))->assertRedirect(route('chatbot.login'));

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_unique_match_sets_awaiting_otp_sends_sms_and_opens_sms_ui(): void
    {
        Mail::fake();
        Notification::fake();

        $household = Household::factory()->create(['household_no' => 'HH-001']);
        Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);
        $account = $this->actingAsResidentAccount();
        $residentIdBefore = $account->resident_id;

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->status);
        $this->assertNotSame(RecordRequest::STATUS_APPROVED, $row->status);
        $this->assertNull($row->approved_at);
        $this->assertSame($residentIdBefore, $account->fresh()->resident_id);
        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);

        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
        Http::assertSentCount(1);

        $sms = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $this->assertStringContainsString('SMS Verification', $sms);
        $this->assertStringContainsString('Try Other Way (Send via Email)', $sms);
        $this->assertStringNotContainsString('Verification Method', $sms);
        Http::assertSentCount(1);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Continue Verification', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_another_account_cannot_open_someone_elses_awaiting_otp_sms_page(): void
    {
        Mail::fake();
        Notification::fake();

        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.sms@example.com',
        ]));
        $this->seedRequest($owner, RecordRequest::STATUS_AWAITING_OTP, [
            'matched_resident_id' => 55,
            'mobile_number_submitted' => '09179998888',
        ]);

        $viewer = $this->actingAsResidentAccount([
            'email' => 'viewer.sms@example.com',
        ]);

        $this->get(route('chatbot.household.verification.sms', [
            'account_id' => $owner->account_id,
            'request_id' => RecordRequest::query()->value('request_id'),
            'status' => 'Awaiting OTP',
        ]))->assertRedirect(route('chatbot.main'));

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringNotContainsString('Continue Verification', $html);
        $this->assertStringNotContainsString('09179998888', $html);
        $this->assertSame(0, RecordRequest::query()->where('account_id', $viewer->account_id)->count());
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, RecordRequest::query()->where('account_id', $owner->account_id)->value('status'));
        $this->assertNull($viewer->fresh()->resident_id);
        $this->assertNull($owner->fresh()->resident_id);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_pending_request_does_not_open_sms_otp_interface(): void
    {
        Mail::fake();
        Notification::fake();

        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_PENDING);

        $this->get(route('chatbot.household.verification.sms'))
            ->assertRedirect(route('chatbot.main'));

        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.main'));

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Continue Verification', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSame(RecordRequest::STATUS_PENDING, RecordRequest::query()->value('status'));
        $this->assertNoOtpOrSmsSideEffects($account);
    }

    public function test_denied_request_does_not_open_sms_otp_interface(): void
    {
        Mail::fake();
        Notification::fake();

        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_DENIED, [
            'decision_reason' => 'The submitted resident information does not match the household record.',
        ]);

        $this->get(route('chatbot.household.verification.sms', ['status' => 'Awaiting OTP']))
            ->assertRedirect(route('chatbot.main'));

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Request Could Not Be Verified', $html);
        $this->assertStringContainsString('The submitted resident information does not match the household record.', $html);
        $this->assertStringNotContainsString('Continue Verification', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSame(RecordRequest::STATUS_DENIED, RecordRequest::query()->value('status'));
        $this->assertNoOtpOrSmsSideEffects($account);
    }

    public function test_awaiting_otp_request_form_redirects_to_sms_ui(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_AWAITING_OTP);

        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
    }

    public function test_query_string_cannot_grant_sms_access_without_awaiting_otp_row(): void
    {
        $this->actingAsResidentAccount();

        $this->get(route('chatbot.household.verification.sms', [
            'verification' => 'Awaiting OTP',
            'state' => 'sms',
            'status' => 'Awaiting OTP',
            'account_id' => '1',
            'request_id' => '1',
        ]))->assertRedirect(route('chatbot.main'));
    }

    public function test_legacy_approved_without_otp_may_open_paused_sms_ui_for_verification(): void
    {
        Mail::fake();
        Notification::fake();

        $account = $this->actingAsResidentAccount(['resident_id' => null]);
        $approvedAt = now()->subMinute();
        $row = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => 42,
        ]);
        $row->approved_at = $approvedAt;
        $row->save();
        $approvedAtStored = $row->fresh()->approved_at?->toJSON();
        $residentIdBefore = $account->resident_id;

        $this->get(route('chatbot.household.verification.sms', [
            'status' => 'Awaiting OTP',
            'account_id' => '999',
            'request_id' => '999',
            'resident_id' => '999',
            'matched_resident_id' => '999',
        ]))->assertOk();

        $fresh = $row->fresh();
        $this->assertSame(RecordRequest::STATUS_APPROVED, $fresh->status);
        $this->assertSame($approvedAtStored, $fresh->approved_at?->toJSON());
        $this->assertSame($residentIdBefore, $account->fresh()->resident_id);
        $this->assertDatabaseCount('record_request_otps', 0);
        $this->assertNoOtpOrSmsSideEffects($account);
    }

    public function test_no_request_cannot_open_sms_ui(): void
    {
        Mail::fake();
        Notification::fake();

        $account = $this->actingAsResidentAccount();

        $this->get(route('chatbot.household.verification.sms', [
            'status' => 'Approved',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 0);
        $this->assertNoOtpOrSmsSideEffects($account);
    }

    public function test_another_account_cannot_open_someone_elses_approved_sms_page(): void
    {
        Mail::fake();
        Notification::fake();

        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.approved.sms@example.com',
        ]));
        $owned = $this->seedRequest($owner, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => 55,
            'mobile_number_submitted' => '09179998888',
        ]);
        $owned->approved_at = now();
        $owned->save();

        $viewer = $this->actingAsResidentAccount([
            'email' => 'viewer.approved.sms@example.com',
        ]);

        $this->get(route('chatbot.household.verification.sms', [
            'account_id' => $owner->account_id,
            'request_id' => $owned->request_id,
            'status' => 'Approved',
            'matched_resident_id' => '55',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $owned->fresh()->status);
        $this->assertSame(0, RecordRequest::query()->where('account_id', $viewer->account_id)->count());
        $this->assertDatabaseCount('record_request_otps', 0);
        $this->assertNull($viewer->fresh()->resident_id);
        $this->assertNull($owner->fresh()->resident_id);
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }
}
