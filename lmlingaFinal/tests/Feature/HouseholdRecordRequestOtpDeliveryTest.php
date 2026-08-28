<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestOtpDelivery;
use App\Support\HouseholdRecordRequestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HouseholdRecordRequestOtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.iprog.base_url', 'https://www.iprogsms.com/api/v1');
        Config::set('services.iprog.api_token', 'test-iprog-token');
        Config::set('services.iprog.timeout', 5);
        Http::preventStrayRequests();
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
            'email' => 'ana.otp.delivery@example.com',
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
            'emailAddress' => 'ana.otp.delivery@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));
        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    private function fakeIprogQueued(string $messageId = 'iSms-Test01'): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'Your SMS message has been successfully added to the queue and will be processed shortly.',
                'message_id' => $messageId,
            ], 200),
        ]);
    }

    private function officialMatchResident(): Resident
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);

        return Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);
    }

    public function test_sms_delivery_service_still_queues_iprog_when_invoked_directly(): void
    {
        Mail::fake();
        Notification::fake();
        $this->fakeIprogQueued('iSms-Match1');
        $resident = $this->officialMatchResident();
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'matched_resident_id' => '999',
            'account_id' => '888',
            'status' => 'Approved',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->status);
        $this->assertSame((int) $resident->getKey(), (int) $row->matched_resident_id);
        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);

        $otp = RecordRequestOtp::query()->first();
        $this->assertNotNull($otp->last_sent_at);
        $this->assertNull($row->fresh()->approved_at);
        $this->assertNull($account->fresh()->resident_id);

        $cooldown = app(HouseholdRecordRequestOtpDelivery::class)
            ->deliverForAwaitingRequest($account, $row);
        $this->assertTrue($cooldown->alreadySent);
        Http::assertSentCount(1);

        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_iprog_send_failure_invalidates_otp_and_allows_retry_issue(): void
    {
        Mail::fake();
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::sequence()
                ->push([
                    'status' => 500,
                    'message' => 'Invalid Token',
                ], 200)
                ->push([
                    'status' => 200,
                    'message' => 'Your SMS message has been successfully added to the queue and will be processed shortly.',
                    'message_id' => 'iSms-Retry1',
                ], 200),
        ]);

        $this->officialMatchResident();
        $account = $this->actingAsResidentAccount([
            'email' => 'ana.otp.fail@example.com',
        ]);

        $this->post(
            route('chatbot.household.verification.store'),
            $this->validPayload(['emailAddress' => 'ana.otp.fail@example.com']),
        )->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertNotNull(RecordRequestOtp::query()->value('invalidated_at'));
        $this->assertNull(RecordRequestOtp::query()->value('last_sent_at'));
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->fresh()->status);
        $this->assertNull($row->fresh()->approved_at);

        $retry = app(HouseholdRecordRequestOtpDelivery::class)
            ->deliverForAwaitingRequest($account, $row->fresh());

        $this->assertTrue($retry->sent);
        $this->assertDatabaseCount('record_request_otps', 2);
        $this->assertNotNull(
            RecordRequestOtp::query()->whereNull('invalidated_at')->whereNotNull('last_sent_at')->first()
        );
        Http::assertSentCount(2);
    }

    public function test_denied_request_creates_no_otp_and_sends_no_sms(): void
    {
        Http::fake();
        $this->officialMatchResident();

        $deniedAccount = $this->actingAsResidentAccount([
            'email' => 'ana.otp.denied@example.com',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'middle_name' => 'X',
        ]);
        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'ana.otp.denied@example.com',
            'firstName' => 'Ana',
            'lastName' => 'Santos',
            'middleName' => 'Cruz',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_DENIED, RecordRequest::query()->value('status'));
        $this->assertDatabaseCount('record_request_otps', 0);
        Http::assertNothingSent();
        $this->assertNull($deniedAccount->fresh()->resident_id);
    }

    public function test_empty_masterlist_pending_sends_no_sms(): void
    {
        Http::fake();
        $account = $this->actingAsResidentAccount([
            'email' => 'ana.otp.pending2@example.com',
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'ana.otp.pending2@example.com',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_PENDING, RecordRequest::query()->value('status'));
        $this->assertDatabaseCount('record_request_otps', 0);
        $this->assertNull($account->fresh()->resident_id);
        Http::assertNothingSent();
    }

    public function test_another_account_cannot_deliver_for_someone_elses_request(): void
    {
        Http::fake();
        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.delivery@example.com',
        ]));
        $row = new RecordRequest;
        $row->account_id = $owner->account_id;
        $row->household_no_submitted = 'HH-001';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09179998888';
        $row->email_submitted = $owner->email;
        $row->submitter_ip = '203.0.113.9';
        $row->matched_resident_id = 55;
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = HouseholdRecordRequestMatcher::REASON_AWAITING_OTP;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        $viewer = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'viewer.delivery@example.com',
        ]));

        $result = app(HouseholdRecordRequestOtpDelivery::class)
            ->deliverForAwaitingRequest($viewer, $row);

        $this->assertFalse($result->sent);
        $this->assertDatabaseCount('record_request_otps', 0);
        Http::assertNothingSent();
    }
}
