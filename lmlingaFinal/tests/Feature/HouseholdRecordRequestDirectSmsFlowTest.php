<?php

namespace Tests\Feature;

use App\Mail\HouseholdRecordVerificationOtpMail;
use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestOtpDelivery;
use App\Services\HouseholdRecordRequestOtpIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HouseholdRecordRequestDirectSmsFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.iprog.base_url' => 'https://www.iprogsms.com/api/v1',
            'services.iprog.api_token' => 'test-iprog-token',
            'services.iprog.timeout' => 5,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'queued',
                'message_id' => 'iSms-Direct-1',
            ], 200),
        ]);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create(array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '2',
            'email' => 'ana.direct.sms@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides));
        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    private function seedOfficialMatch(): Resident
    {
        $household = Household::factory()->create(['household_no' => 'HH-DIRECT-1']);

        return Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'householdNo' => 'HH-DIRECT-1',
            'relationship' => 'Household Head',
            'firstName' => 'Ana',
            'middleName' => 'Cruz',
            'lastName' => 'Santos',
            'mobileNumber' => '09171234567',
            'emailAddress' => 'ana.direct.sms@example.com',
        ], $overrides);
    }

    public function test_successful_match_awaits_otp_sends_one_sms_and_lands_on_sms_ui(): void
    {
        Mail::fake();
        $resident = $this->seedOfficialMatch();
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->status);
        $this->assertSame((string) $resident->getKey(), (string) $row->matched_resident_id);
        $this->assertNull($row->approved_at);
        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);

        // Method page is not the landing destination after match.
        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $sms = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $this->assertStringContainsString('SMS Verification', $sms);
        $this->assertStringNotContainsString('Verification Method', $sms);
        $this->assertStringContainsString('Try Other Way (Send via Email)', $sms);
        Http::assertSentCount(1);
        $this->assertNull($account->fresh()->resident_id);
        Mail::assertNothingOutgoing();
    }

    public function test_existing_awaiting_otp_continuation_opens_sms_without_resending(): void
    {
        Mail::fake();
        $this->seedOfficialMatch();
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.household.verification.sms'));
        Http::assertSentCount(1);

        $main = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Continue Verification', $main);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $main);

        $this->get(route('chatbot.household.verification.sms'))->assertOk();
        $this->get(route('chatbot.household.verification.sms'))->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('record_request_otps', 1);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_sms_send_failure_keeps_awaiting_otp_and_allows_email_alternative(): void
    {
        Mail::fake();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 500,
                'message' => 'fail',
            ], 200),
        ]);
        $this->seedOfficialMatch();
        $account = $this->actingAsResidentAccount(['email' => 'ana.direct.fail@example.com']);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'ana.direct.fail@example.com',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->status);
        $otp = RecordRequestOtp::query()->where('request_id', $row->request_id)->first();
        $this->assertNotNull($otp);
        $this->assertNotNull($otp->invalidated_at);
        $this->assertNull($row->approved_at);

        $sms = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $this->assertStringContainsString('Try Other Way (Send via Email)', $sms);
        $this->assertStringContainsString(route('chatbot.household.verification.email.send'), $sms);

        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.household.verification.email'));
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_resend_respects_cooldown_and_sms_verify_still_approves(): void
    {
        Mail::fake();
        $resident = $this->seedOfficialMatch();
        $account = $this->actingAsResidentAccount(['email' => 'ana.direct.verify@example.com']);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'ana.direct.verify@example.com',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));
        Http::assertSentCount(1);
        $firstId = RecordRequestOtp::query()->value('otp_id');

        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
        $this->assertSame($firstId, RecordRequestOtp::query()->whereNull('invalidated_at')->value('otp_id'));
        Http::assertSentCount(1);

        $this->travel(HouseholdRecordRequestOtpDelivery::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'queued',
                'message_id' => 'iSms-Direct-2',
            ], 200),
        ]);
        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
        $this->assertNotNull(RecordRequestOtp::query()->find($firstId)?->invalidated_at);

        $active = RecordRequestOtp::query()->whereNull('invalidated_at')->orderByDesc('otp_id')->first();
        $this->assertNotNull($active);
        $code = '445566';
        $active->code_hash = Hash::make($code);
        $active->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_MOBILE, '09171234567');
        $active->save();

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.information'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, RecordRequest::query()->value('status'));
        $this->assertNotNull(RecordRequest::query()->value('approved_at'));
        $this->assertSame((string) $resident->getKey(), (string) $account->fresh()->resident_id);
        $this->get(route('chatbot.household.information'))->assertOk();
    }
}
