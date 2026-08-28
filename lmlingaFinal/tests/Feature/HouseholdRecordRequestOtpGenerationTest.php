<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestOtpIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HouseholdRecordRequestOtpGenerationTest extends TestCase
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
                'message_id' => 'iSms-OtpGen',
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
            'email' => 'ana.otp.gen@example.com',
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
            'emailAddress' => 'ana.otp.gen@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    /**
     * @return array{0: Household, 1: Resident}
     */
    private function officialHouseholdResident(): array
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);

        return [$household, $resident];
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
        $row->submitter_ip = '203.0.113.50';
        $row->matched_resident_id = $overrides['matched_resident_id'] ?? null;
        $row->status = $status;
        $row->decision_reason = $overrides['decision_reason'] ?? null;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    public function test_unique_match_sets_awaiting_otp_and_auto_delivers_sms_otp(): void
    {
        Mail::fake();
        Notification::fake();

        $this->officialHouseholdResident();
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'request_id' => '999',
            'account_id' => '888',
            'status' => 'Approved',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));

        $request = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->status);
        $this->assertNotSame(RecordRequest::STATUS_APPROVED, $request->status);
        $this->assertNull($request->approved_at);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_issuer_hash_check_succeeds_for_generated_plaintext_only_in_memory(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'ana.otp.issuer@example.com',
        ]));
        $request = $this->seedRequest($account, RecordRequest::STATUS_AWAITING_OTP, [
            'matched_resident_id' => 4,
        ]);

        $issued = app(HouseholdRecordRequestOtpIssuer::class)->issueForOwnedAwaitingRequest($account, $request);

        $this->assertNotNull($issued);
        $this->assertFalse($issued->reused);
        $this->assertNotNull($issued->plaintext);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $issued->plaintext);
        $this->assertTrue(Hash::check($issued->plaintext, $issued->otp->code_hash));
        $this->assertFalse(Hash::check('000000', $issued->otp->code_hash));
        $this->assertDatabaseMissing('record_request_otps', ['code_hash' => $issued->plaintext]);
        $this->assertNull($issued->otp->last_sent_at);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
    }

    public function test_existing_active_otp_is_reused_without_generating_another_code(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'ana.otp.reuse@example.com',
        ]));
        $request = $this->seedRequest($account, RecordRequest::STATUS_AWAITING_OTP);

        $first = app(HouseholdRecordRequestOtpIssuer::class)->issueForOwnedAwaitingRequest($account, $request);
        $second = app(HouseholdRecordRequestOtpIssuer::class)->issueForOwnedAwaitingRequest($account, $request);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertTrue($second->reused);
        $this->assertNull($second->plaintext);
        $this->assertSame((int) $first->otp->otp_id, (int) $second->otp->otp_id);
        $this->assertTrue(Hash::check($first->plaintext, $second->otp->fresh()->code_hash));
        $this->assertDatabaseCount('record_request_otps', 1);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
    }

    public function test_sms_get_after_awaiting_otp_match_does_not_send_another_sms(): void
    {
        Mail::fake();
        $this->officialHouseholdResident();
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);

        $this->get(route('chatbot.household.verification.sms', [
            'request_id' => '1',
            'account_id' => '1',
            'status' => 'Awaiting OTP',
        ]))->assertOk();

        $this->get(route('chatbot.household.verification.sms'))
            ->assertOk();

        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, RecordRequest::query()->value('status'));
        Mail::assertNothingOutgoing();
    }

    public function test_another_account_cannot_issue_otp_for_someone_elses_request(): void
    {
        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.otp.gen@example.com',
        ]));
        $ownerRequest = $this->seedRequest($owner, RecordRequest::STATUS_AWAITING_OTP);

        $viewer = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'viewer.otp.gen@example.com',
        ]));

        $issued = app(HouseholdRecordRequestOtpIssuer::class)
            ->issueForOwnedAwaitingRequest($viewer, $ownerRequest);

        $this->assertNull($issued);
        $this->assertDatabaseCount('record_request_otps', 0);
        $this->assertNull($owner->fresh()->resident_id);
        $this->assertNull($viewer->fresh()->resident_id);
    }

    public function test_pending_and_denied_requests_do_not_receive_otp_rows(): void
    {
        Mail::fake();
        Notification::fake();

        $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_PENDING, RecordRequest::query()->value('status'));
        $this->assertDatabaseCount('record_request_otps', 0);

        $deniedAccount = $this->actingAsResidentAccount([
            'email' => 'ana.otp.denied@example.com',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
        Household::factory()->create(['household_no' => 'HH-001']);
        $this->post(route('chatbot.household.verification.store'), [
            'householdNo' => 'HH-001',
            'relationship' => 'Household Head',
            'firstName' => 'Ana',
            'middleName' => 'Cruz',
            'lastName' => 'Santos',
            'mobileNumber' => '09171234567',
            'emailAddress' => 'ana.otp.denied@example.com',
        ])->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_DENIED, RecordRequest::query()->where('account_id', $deniedAccount->account_id)->value('status'));
        $this->assertSame(0, RecordRequestOtp::query()->where('request_id', RecordRequest::query()->where('account_id', $deniedAccount->account_id)->value('request_id'))->count());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_issuer_refuses_pending_status_even_when_owned(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'ana.otp.pending@example.com',
        ]));
        $request = $this->seedRequest($account, RecordRequest::STATUS_PENDING);

        $issued = app(HouseholdRecordRequestOtpIssuer::class)->issueForOwnedAwaitingRequest($account, $request);

        $this->assertNull($issued);
        $this->assertDatabaseCount('record_request_otps', 0);
        $this->assertSame(RecordRequest::STATUS_PENDING, $request->fresh()->status);
    }
}
