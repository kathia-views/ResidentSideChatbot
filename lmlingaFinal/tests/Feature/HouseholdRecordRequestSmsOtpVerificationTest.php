<?php

namespace Tests\Feature;

use App\Mail\HouseholdRecordVerificationOtpMail;
use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdOtpAttemptGuard;
use App\Services\HouseholdRecordRequestOtpDelivery;
use App\Services\HouseholdRecordRequestOtpIssuer;
use App\Support\HouseholdRecordRequestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HouseholdRecordRequestSmsOtpVerificationTest extends TestCase
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
    }

    private function fakeIprogQueued(string $messageId = 'iSms-Test-1'): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'Your SMS message has been successfully added to the queue and will be processed shortly.',
                'message_id' => $messageId,
            ], 200),
        ]);
    }

    private function fakeIprogProviderFailure(): void
    {
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 500,
                'message' => 'fail',
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
            'email' => 'ana.sms.otp@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));
        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    private function chatbotRelationshipKey(Resident $resident): mixed
    {
        return $resident->getAttribute(Resident::resolvedPrimaryKeyName());
    }

    /**
     * @return array{0: ResidentAccount, 1: Resident, 2: RecordRequest}
     */
    private function awaitingOtpSetup(?string $mobile = '09171234567'): array
    {
        $account = $this->actingAsResidentAccount();
        $household = Household::factory()->create(['household_no' => 'HH-SMS-'.uniqid()]);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-'.substr(uniqid(), -6),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'relation' => 'Head',
        ]);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = (string) $household->household_no;
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = $mobile;
        $row->email_submitted = 'forged@example.com';
        $row->submitter_ip = '203.0.113.50';
        $row->matched_resident_id = $this->chatbotRelationshipKey($resident);
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = HouseholdRecordRequestMatcher::REASON_AWAITING_OTP;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        return [$account->fresh(), $resident, $row->fresh()];
    }

    /**
     * @return array{0: RecordRequestOtp, 1: string}
     */
    private function issueActiveSmsOtp(RecordRequest $request, string $mobile, string $code = '654321'): array
    {
        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = Hash::make($code);
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_MOBILE, $mobile);
        $otp->expires_at = now()->addMinutes(5);
        $otp->attempt_count = 0;
        $otp->resend_count = 0;
        $otp->last_sent_at = now();
        $otp->verified_at = null;
        $otp->invalidated_at = null;
        $otp->save();

        return [$otp->fresh(), $code];
    }

    public function test_sms_method_option_enabled(): void
    {
        $this->awaitingOtpSetup();

        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $html = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $this->assertStringContainsString('SMS Verification', $html);
        $this->assertStringContainsString(route('chatbot.household.verification.sms.send'), $html);
        $this->assertStringContainsString('Try Other Way (Send via Email)', $html);
        $this->assertStringNotContainsString('temporarily paused', $html);
        $this->assertStringNotContainsString('Verification Method', $html);
    }

    public function test_sms_send_issues_otp_invokes_iprog_and_stays_awaiting(): void
    {
        Mail::fake();
        $this->fakeIprogQueued();
        [$account, , $request] = $this->awaitingOtpSetup();

        $this->post(route('chatbot.household.verification.sms.send'), [
            'mobile' => '09998887777',
            'account_id' => 999,
            'request_id' => 999,
        ])->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($request->fresh()->approved_at);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertDatabaseCount('record_request_otps', 1);

        $otp = RecordRequestOtp::query()->first();
        $this->assertNotNull($otp->last_sent_at);
        $this->assertNull($otp->verified_at);
        $this->assertNull($otp->invalidated_at);

        Http::assertSentCount(1);
        Http::assertSent(function ($httpRequest) {
            $data = $httpRequest->data();

            return $httpRequest->url() === 'https://www.iprogsms.com/api/v1/sms_messages'
                && ($data['phone_number'] ?? null) === '09171234567'
                && ($data['phone_number'] ?? null) !== '09998887777'
                && str_contains((string) ($data['message'] ?? ''), 'LMLINGA verification code:')
                && ! array_key_exists('matched_resident_id', $data)
                && ! array_key_exists('account_id', $data)
                && ! array_key_exists('request_id', $data);
        });
        Mail::assertNothingOutgoing();
    }

    public function test_forged_mobile_is_ignored_recipient_from_record_only(): void
    {
        $this->fakeIprogQueued();
        [$account, , $request] = $this->awaitingOtpSetup('09170001111');

        $this->post(route('chatbot.household.verification.sms.send'), [
            'mobileNumber' => '09179999999',
            'mobile' => '09179999999',
        ])->assertRedirect(route('chatbot.household.verification.sms'));

        Http::assertSent(fn ($req) => $req['phone_number'] === '09170001111');
        $this->assertSame('09170001111', $request->fresh()->mobile_number_submitted);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_delivery_failure_invalidates_otp(): void
    {
        $this->fakeIprogProviderFailure();
        [, , $request] = $this->awaitingOtpSetup();

        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $otp = RecordRequestOtp::query()->where('request_id', $request->request_id)->first();
        $this->assertNotNull($otp);
        $this->assertNotNull($otp->invalidated_at);
        $this->assertNull($otp->last_sent_at);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
    }

    public function test_resend_works_after_cooldown_and_blocked_before(): void
    {
        $this->fakeIprogQueued('iSms-Test-1');
        [, , $request] = $this->awaitingOtpSetup();

        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
        $firstId = RecordRequestOtp::query()->value('otp_id');

        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
        $this->assertSame(1, RecordRequestOtp::query()->whereNull('invalidated_at')->count());
        $this->assertSame($firstId, RecordRequestOtp::query()->whereNull('invalidated_at')->value('otp_id'));

        $this->travel(HouseholdRecordRequestOtpDelivery::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->fakeIprogQueued('iSms-Test-2');

        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertNotNull(RecordRequestOtp::query()->find($firstId)?->invalidated_at);
        $this->assertSame(1, RecordRequestOtp::query()->whereNull('invalidated_at')->count());
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
    }

    public function test_wrong_otp_attempts_then_lock_and_reject_correct_during_lock(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        [, $code] = $this->issueActiveSmsOtp($request, '09171234567', '654321');

        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '000000'])
                ->assertRedirect(route('chatbot.household.verification.sms'));
        }

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '000000'])
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $guard = app(HouseholdOtpAttemptGuard::class);
        $this->assertTrue($guard->isLocked(
            (int) $account->account_id,
            (int) $request->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        ));

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertNull(RecordRequestOtp::query()->value('verified_at'));
    }

    public function test_resend_does_not_reset_verification_lock(): void
    {
        $this->fakeIprogQueued();
        [$account, , $request] = $this->awaitingOtpSetup();
        $this->issueActiveSmsOtp($request, '09171234567', '111111');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '000000']);
        }

        $this->travel(HouseholdRecordRequestOtpDelivery::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertTrue(app(HouseholdOtpAttemptGuard::class)->isLocked(
            (int) $account->account_id,
            (int) $request->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        ));

        $freshCodeOtp = RecordRequestOtp::query()
            ->where('request_id', $request->request_id)
            ->whereNull('invalidated_at')
            ->whereNull('verified_at')
            ->orderByDesc('otp_id')
            ->first();
        $this->assertNotNull($freshCodeOtp);

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '999999'])
            ->assertRedirect(route('chatbot.household.verification.sms'));
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
    }

    public function test_expired_otp_rejected(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        [$otp, $code] = $this->issueActiveSmsOtp($request, '09171234567');
        $otp->expires_at = now()->subMinute();
        $otp->save();

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_email_otp_cannot_verify_through_sms_endpoint(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        $emailCode = '111222';
        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = Hash::make($emailCode);
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_EMAIL, (string) $account->email);
        $otp->expires_at = now()->addMinutes(5);
        $otp->last_sent_at = now();
        $otp->save();

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $emailCode])
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($otp->fresh()->verified_at);
    }

    public function test_sms_otp_cannot_verify_through_email_endpoint(): void
    {
        Mail::fake();
        [$account, , $request] = $this->awaitingOtpSetup();
        [, $smsCode] = $this->issueActiveSmsOtp($request, '09171234567', '333444');

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $smsCode])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_successful_sms_otp_approves_links_and_redirects(): void
    {
        [$account, $resident, $request] = $this->awaitingOtpSetup();
        [, $code] = $this->issueActiveSmsOtp($request, '09171234567', '778899');

        $this->post(route('chatbot.household.verification.sms.verify'), [
            'otp' => $code,
            'mobile' => '09990001111',
            'account_id' => 999,
        ])->assertRedirect(route('chatbot.household.information'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->approved_at);
        $this->assertNotNull(RecordRequestOtp::query()->value('verified_at'));
        $this->assertSame(
            (string) $this->chatbotRelationshipKey($resident),
            (string) $account->fresh()->resident_id,
        );
    }

    public function test_fully_approved_request_cannot_reopen_sms_otp_flow(): void
    {
        [$account, $resident, $request] = $this->awaitingOtpSetup();
        [, $code] = $this->issueActiveSmsOtp($request, '09171234567', '121212');
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.information'));

        $this->get(route('chatbot.household.verification.sms'))
            ->assertRedirect(route('chatbot.main'));
        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.main'));
        $this->assertSame((string) $this->chatbotRelationshipKey($resident), (string) $account->fresh()->resident_id);
    }

    public function test_foreign_account_cannot_send_or_verify_sms(): void
    {
        [, , $ownerRequest] = $this->awaitingOtpSetup();
        $this->issueActiveSmsOtp($ownerRequest, '09171234567', '565656');

        $viewer = $this->actingAsResidentAccount(['email' => 'viewer.sms.otp@example.com']);

        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.main'));
        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '565656'])
            ->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $ownerRequest->fresh()->status);
        $this->assertNull($viewer->fresh()->resident_id);
        $this->assertNull(RecordRequestOtp::query()->value('verified_at'));
    }

    public function test_email_flow_still_works_after_sms_reactivation(): void
    {
        Mail::fake();
        [$account, $resident, $request] = $this->awaitingOtpSetup();

        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.household.verification.email'));
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class);

        $otp = RecordRequestOtp::query()->whereNull('invalidated_at')->orderByDesc('otp_id')->first();
        $this->assertNotNull($otp);

        // Use issuer-known plaintext path: re-hash a known code by replacing row for assert.
        $otp->code_hash = Hash::make('998877');
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_EMAIL, (string) $account->email);
        $otp->save();

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => '998877'])
            ->assertRedirect(route('chatbot.household.information'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame(
            (string) $this->chatbotRelationshipKey($resident),
            (string) $account->fresh()->resident_id,
        );
    }
}
