<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdEmailOtpAttemptGuard;
use App\Services\HouseholdOtpAttemptGuard;
use App\Services\HouseholdRecordRequestOtpDelivery;
use App\Services\HouseholdRecordRequestOtpIssuer;
use App\Support\HouseholdRecordRequestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HouseholdOtpCountdownAndLockoutTest extends TestCase
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
                'message_id' => 'iSms-Countdown',
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
            'email' => 'ana.countdown@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides));
        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    /**
     * @return array{0: ResidentAccount, 1: Resident, 2: RecordRequest}
     */
    private function awaitingSetup(string $email = 'ana.countdown@example.com'): array
    {
        $account = $this->actingAsResidentAccount(['email' => $email]);
        $household = Household::factory()->create(['household_no' => 'HH-CD-'.uniqid()]);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = (string) $household->household_no;
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $email;
        $row->submitter_ip = '127.0.0.1';
        $row->matched_resident_id = $resident->getKey();
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = HouseholdRecordRequestMatcher::REASON_AWAITING_OTP;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        return [$account->fresh(), $resident, $row->fresh()];
    }

    private function issueSmsOtp(RecordRequest $request, string $mobile = '09171234567', int $remainingSeconds = 300): RecordRequestOtp
    {
        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = Hash::make('123456');
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_MOBILE, $mobile);
        $otp->expires_at = now()->addSeconds($remainingSeconds);
        $otp->last_sent_at = now();
        $otp->save();

        return $otp->fresh();
    }

    private function issueEmailOtp(ResidentAccount $account, RecordRequest $request, int $remainingSeconds = 300): RecordRequestOtp
    {
        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = Hash::make('123456');
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_EMAIL, (string) $account->email);
        $otp->expires_at = now()->addSeconds($remainingSeconds);
        $otp->last_sent_at = now();
        $otp->save();

        return $otp->fresh();
    }

    private function otpSecondsFromHtml(string $html): int
    {
        $this->assertMatchesRegularExpression('/data-otp-seconds="(\d+)"/', $html);
        preg_match('/data-otp-seconds="(\d+)"/', $html, $matches);

        return (int) $matches[1];
    }

    /**
     * Mirrors chatbot-household-sms.js formatCountdown.
     */
    private function formatCountdown(int $totalSeconds): string
    {
        $safe = max(0, $totalSeconds);
        $minutes = str_pad((string) intdiv($safe, 60), 2, '0', STR_PAD_LEFT);
        $seconds = str_pad((string) ($safe % 60), 2, '0', STR_PAD_LEFT);

        return $minutes.':'.$seconds;
    }

    public function test_new_sms_and_email_otp_countdown_never_exceeds_expiry_window(): void
    {
        Mail::fake();
        [$account, , $request] = $this->awaitingSetup();
        $this->issueSmsOtp($request, '09171234567', HouseholdRecordRequestOtpIssuer::EXPIRY_MINUTES * 60);

        $smsSeconds = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent()
        );
        $this->assertLessThanOrEqual(300, $smsSeconds);
        $this->assertGreaterThan(290, $smsSeconds);
        $this->assertStringContainsString('05:', $this->formatCountdown($smsSeconds));

        RecordRequestOtp::query()->delete();
        $this->issueEmailOtp($account, $request, HouseholdRecordRequestOtpIssuer::EXPIRY_MINUTES * 60);
        $emailSeconds = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.email'))->assertOk()->getContent()
        );
        $this->assertLessThanOrEqual(300, $emailSeconds);
        $this->assertGreaterThan(290, $emailSeconds);
    }

    public function test_inflated_expires_at_is_clamped_and_cannot_show_479_or_500(): void
    {
        [$account, , $request] = $this->awaitingSetup('ana.clamp@example.com');

        $otp = $this->issueSmsOtp($request);
        $otp->expires_at = now()->addHours(8)->addSeconds(2);
        $otp->save();

        $seconds = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent()
        );
        $this->assertSame(300, $seconds);
        $this->assertSame('05:00', $this->formatCountdown($seconds));
        $this->assertNotSame('500:00', $this->formatCountdown($seconds));
        // Old inflated values must not appear from clamped remaining.
        $this->assertStringNotContainsString('479:58', $this->get(route('chatbot.household.verification.sms'))->getContent());
        $this->assertStringNotContainsString('500:00', $this->get(route('chatbot.household.verification.sms'))->getContent());

        RecordRequestOtp::query()->delete();
        $emailOtp = $this->issueEmailOtp($account, $request);
        $emailOtp->expires_at = now()->addSeconds(30000);
        $emailOtp->save();

        $emailSeconds = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.email'))->assertOk()->getContent()
        );
        $this->assertSame(300, $emailSeconds);
    }

    public function test_remaining_141_seconds_renders_as_02_21_and_refresh_does_not_reset(): void
    {
        [, , $request] = $this->awaitingSetup('ana.refresh@example.com');
        $this->issueSmsOtp($request, '09171234567', 141);

        $first = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent()
        );
        $this->assertSame(141, $first);
        $this->assertSame('02:21', $this->formatCountdown($first));

        $this->travel(34)->seconds();
        $second = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent()
        );
        $this->assertSame(107, $second);
        $this->assertNotSame(300, $second);
        $this->assertSame('01:47', $this->formatCountdown($second));
    }

    public function test_expired_otp_returns_zero_seconds(): void
    {
        [, , $request] = $this->awaitingSetup('ana.expired@example.com');
        $otp = $this->issueSmsOtp($request, '09171234567', 60);
        $otp->expires_at = now()->subSecond();
        $otp->save();

        $seconds = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent()
        );
        $this->assertSame(0, $seconds);
        $this->assertSame('00:00', $this->formatCountdown($seconds));
    }

    public function test_resend_restarts_countdown_from_new_expiry(): void
    {
        Mail::fake();
        [, , $request] = $this->awaitingSetup('ana.resend.cd@example.com');
        $this->issueSmsOtp($request, '09171234567', 40);

        $before = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent()
        );
        $this->assertSame(40, $before);

        $this->travel(HouseholdRecordRequestOtpDelivery::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $after = $this->otpSecondsFromHtml(
            $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent()
        );
        $this->assertGreaterThan(290, $after);
        $this->assertLessThanOrEqual(300, $after);
    }

    public function test_frontend_formatter_contract_values(): void
    {
        $cases = [
            300 => '05:00',
            299 => '04:59',
            141 => '02:21',
            120 => '02:00',
            9 => '00:09',
            0 => '00:00',
        ];

        foreach ($cases as $seconds => $expected) {
            $this->assertSame($expected, $this->formatCountdown($seconds));
        }
    }

    public function test_wrong_attempts_show_fixed_message_and_fifth_locks_for_120_seconds(): void
    {
        [$account, , $request] = $this->awaitingSetup('ana.lock.cd@example.com');
        $otp = $this->issueSmsOtp($request);
        $otp->code_hash = Hash::make('654321');
        $otp->save();

        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '000000'])
                ->assertRedirect(route('chatbot.household.verification.sms'))
                ->assertSessionHas('household_otp_verify_error', 'The verification code is incorrect.');
            $this->assertFalse(app(HouseholdOtpAttemptGuard::class)->isLocked(
                (int) $account->account_id,
                (int) $request->request_id,
                HouseholdOtpAttemptGuard::CHANNEL_SMS,
            ));
        }

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '000000'])
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $guard = app(HouseholdOtpAttemptGuard::class);
        $this->assertSame(120, HouseholdOtpAttemptGuard::LOCK_SECONDS);
        $this->assertSame(120, HouseholdEmailOtpAttemptGuard::LOCK_SECONDS);
        $this->assertTrue($guard->isLocked(
            (int) $account->account_id,
            (int) $request->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        ));
        $remaining = $guard->remainingLockSeconds(
            (int) $account->account_id,
            (int) $request->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        );
        $this->assertGreaterThan(110, $remaining);
        $this->assertLessThanOrEqual(120, $remaining);

        $this->post(route('chatbot.household.verification.sms.verify'), ['otp' => '654321'])
            ->assertRedirect(route('chatbot.household.verification.sms'));
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);

        $this->travel(HouseholdRecordRequestOtpDelivery::RESEND_COOLDOWN_SECONDS + 1)->seconds();
        $this->post(route('chatbot.household.verification.sms.send'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
        $this->assertTrue($guard->isLocked(
            (int) $account->account_id,
            (int) $request->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        ));

        $this->travel(88)->seconds();
        $this->assertTrue($guard->isLocked(
            (int) $account->account_id,
            (int) $request->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        ));

        $this->travel(2)->seconds();
        $this->assertFalse($guard->isLocked(
            (int) $account->account_id,
            (int) $request->request_id,
            HouseholdOtpAttemptGuard::CHANNEL_SMS,
        ));
    }
}
