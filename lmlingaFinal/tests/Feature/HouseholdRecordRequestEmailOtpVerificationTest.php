<?php

namespace Tests\Feature;

use App\Mail\HouseholdRecordVerificationOtpMail;
use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdOtpVerifyResult;
use App\Services\HouseholdRecordRequestOtpIssuer;
use App\Support\HouseholdRecordRequestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HouseholdRecordRequestEmailOtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
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
            'email' => 'ana.verify.otp@example.com',
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
    private function awaitingOtpSetup(?ResidentAccount $account = null): array
    {
        $account ??= $this->actingAsResidentAccount();
        $household = Household::factory()->create(['household_no' => 'HH-001-'.uniqid()]);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-'.substr(uniqid(), -6),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'relation' => 'Head',
        ]);
        $residentKey = $this->chatbotRelationshipKey($resident);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = (string) $household->household_no;
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = 'forged@example.com';
        $row->submitter_ip = '203.0.113.50';
        $row->matched_resident_id = $residentKey;
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
    private function issueActiveEmailOtp(ResidentAccount $account, RecordRequest $request, string $code = '654321'): array
    {
        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = Hash::make($code);
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_EMAIL, (string) $account->email);
        $otp->expires_at = now()->addMinutes(5);
        $otp->attempt_count = 0;
        $otp->resend_count = 0;
        $otp->last_sent_at = now();
        $otp->verified_at = null;
        $otp->invalidated_at = null;
        $otp->save();

        return [$otp->fresh(), $code];
    }

    private function seedOlderAwaiting(ResidentAccount $account): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = 'HH-OLD';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Spouse';
        $row->first_name_submitted = 'Old';
        $row->middle_name_submitted = 'X';
        $row->last_name_submitted = 'Row';
        $row->mobile_number_submitted = '09170000000';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '127.0.0.1';
        $row->matched_resident_id = 91;
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = HouseholdRecordRequestMatcher::REASON_AWAITING_OTP;
        $row->evaluated_at = now()->subHour();
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    public function test_normal_awaiting_otp_email_verify_approves_links_and_grants_access(): void
    {
        Mail::fake();
        [$account, $resident, $request] = $this->awaitingOtpSetup();
        [, $code] = $this->issueActiveEmailOtp($account, $request);

        $this->post(route('chatbot.household.verification.email.verify'), [
            'otp' => $code,
            'account_id' => '999',
            'request_id' => '888',
            'resident_id' => '777',
            'matched_resident_id' => '666',
            'email' => 'forged@example.com',
            'status' => 'Denied',
        ])->assertRedirect(route('chatbot.household.information'));

        $fresh = $request->fresh();
        $this->assertSame(RecordRequest::STATUS_APPROVED, $fresh->status);
        $this->assertNotNull($fresh->approved_at);
        $this->assertSame((string) $this->chatbotRelationshipKey($resident), (string) $fresh->matched_resident_id);
        $this->assertSame((string) $this->chatbotRelationshipKey($resident), (string) $account->fresh()->resident_id);
        $this->assertNotNull(
            RecordRequestOtp::query()->where('request_id', $fresh->request_id)->whereNotNull('verified_at')->first()
        );

        $main = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Access Household Record', $main);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.information')).'"', $main);
        $this->assertStringNotContainsString('Continue Verification', $main);
        $this->assertStringNotContainsString('Request Household Record', $main);
        $householdPage = $this->get(route('chatbot.household.information'))
            ->assertOk()
            ->assertSee('Ana Cruz Santos', false)
            ->assertSee('Access Household Record', false)
            ->getContent();
        $this->assertStringContainsString('aria-current="page"', $householdPage);

        // Persistence: refresh Main without query string still shows verified CTA.
        $refreshed = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Access Household Record', $refreshed);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.information')).'"', $refreshed);

        // Later session for the same verified account still resolves from DB.
        $this->flushSession();
        $this->withSession(['resident_account_id' => $account->account_id]);
        $laterSession = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Access Household Record', $laterSession);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.information')).'"', $laterSession);
        $this->get(route('chatbot.household.information'))
            ->assertOk()
            ->assertDontSee('Continue Verification', false);

        Mail::assertNothingOutgoing();
        Http::assertNothingSent();
    }

    public function test_legacy_approved_without_otp_denied_until_email_verified(): void
    {
        Mail::fake();
        $household = Household::factory()->create(['household_no' => '151']);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-LEG-V1',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'relation' => 'Head',
        ]);
        $residentKey = $this->chatbotRelationshipKey($resident);
        $account = $this->actingAsResidentAccount(['resident_id' => $residentKey]);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = '151';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '203.0.113.51';
        $row->matched_resident_id = $residentKey;
        $row->status = RecordRequest::STATUS_APPROVED;
        $row->decision_reason = null;
        $row->evaluated_at = now()->subDay();
        $row->approved_at = now()->subDay();
        $row->save();

        $main = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Continue Verification', $main);
        $this->assertStringNotContainsString('Access Household Record', $main);
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));

        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.household.verification.email'));
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class);

        $otp = RecordRequestOtp::query()->where('request_id', $row->request_id)->first();
        $this->assertNotNull($otp);
        $this->assertNull($otp->verified_at);

        $plaintext = null;
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class, function (HouseholdRecordVerificationOtpMail $mail) use (&$plaintext) {
            $property = (new \ReflectionClass($mail))->getProperty('otpCode');
            $property->setAccessible(true);
            $plaintext = $property->getValue($mail);

            return is_string($plaintext) && preg_match('/^\d{6}$/', $plaintext) === 1;
        });
        $this->assertNotNull($plaintext);

        $approvedAtBefore = $row->fresh()->approved_at?->toJSON();

        $this->post(route('chatbot.household.verification.email.verify'), [
            'otp' => $plaintext,
        ])->assertRedirect(route('chatbot.household.information'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $row->fresh()->status);
        $this->assertSame($approvedAtBefore, $row->fresh()->approved_at?->toJSON());
        $this->assertNotNull($otp->fresh()->verified_at);

        $mainAfter = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Access Household Record', $mainAfter);
        $this->get(route('chatbot.household.information'))->assertOk();
    }

    public function test_wrong_otp_does_not_approve_or_link(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        $this->issueActiveEmailOtp($account, $request, '111111');

        $this->from(route('chatbot.household.verification.email'))
            ->post(route('chatbot.household.verification.email.verify'), ['otp' => '999999'])
            ->assertRedirect(route('chatbot.household.verification.email'))
            ->assertSessionHas(
                'household_otp_verify_error',
                'The verification code is incorrect.'
            );

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($request->fresh()->approved_at);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertNull(RecordRequestOtp::query()->value('verified_at'));
        $this->assertSame(1, (int) RecordRequestOtp::query()->value('attempt_count'));
    }

    public function test_resend_email_invalidates_prior_otp_and_sends_new_code(): void
    {
        Mail::fake();
        [$account, , $request] = $this->awaitingOtpSetup();
        [$oldOtp, $oldCode] = $this->issueActiveEmailOtp($account, $request, '111111');
        $oldOtp->last_sent_at = now()->subMinutes(2);
        $oldOtp->save();

        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertNotNull($oldOtp->fresh()->invalidated_at);
        $this->assertDatabaseCount('record_request_otps', 2);
        $newOtp = RecordRequestOtp::query()->whereNull('invalidated_at')->first();
        $this->assertNotNull($newOtp);
        $this->assertNotNull($newOtp->last_sent_at);
        $this->assertFalse(Hash::check($oldCode, (string) $newOtp->code_hash));
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class);
    }

    public function test_five_wrong_attempts_lock_verification_for_two_minutes(): void
    {
        Mail::fake();
        [$account, , $request] = $this->awaitingOtpSetup();
        [$otp, $code] = $this->issueActiveEmailOtp($account, $request, '555555');

        for ($i = 1; $i <= 4; $i++) {
            $this->post(route('chatbot.household.verification.email.verify'), ['otp' => '000000'])
                ->assertRedirect(route('chatbot.household.verification.email'));
            $this->assertFalse(app(\App\Services\HouseholdEmailOtpAttemptGuard::class)
                ->isLocked((int) $account->account_id, (int) $request->request_id));
        }

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => '000000'])
            ->assertRedirect(route('chatbot.household.verification.email'))
            ->assertSessionHas('household_otp_verify_error');

        $guard = app(\App\Services\HouseholdEmailOtpAttemptGuard::class);
        $this->assertTrue($guard->isLocked((int) $account->account_id, (int) $request->request_id));
        $this->assertSame(120, \App\Services\HouseholdEmailOtpAttemptGuard::LOCK_SECONDS);
        $this->assertGreaterThan(110, $guard->remainingLockSeconds((int) $account->account_id, (int) $request->request_id));
        $this->assertLessThanOrEqual(120, $guard->remainingLockSeconds((int) $account->account_id, (int) $request->request_id));

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($otp->fresh()->verified_at);
        $this->assertNull($account->fresh()->resident_id);

        $html = $this->get(route('chatbot.household.verification.email'))->assertOk()->getContent();
        $this->assertStringContainsString('data-otp-locked="true"', $html);
        $this->assertStringContainsString('You have reached the maximum number of attempts', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('attempts remaining', $html);

        $this->withSession(['resident_account_id' => $account->account_id]);
        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.email'));
        $this->assertNull($otp->fresh()->verified_at);

        $this->travel(31)->seconds();
        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.household.verification.email'));
        $this->assertTrue($guard->isLocked((int) $account->account_id, (int) $request->request_id));
        $this->assertGreaterThan(80, $guard->remainingLockSeconds((int) $account->account_id, (int) $request->request_id));

        $plaintext = null;
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class, function (HouseholdRecordVerificationOtpMail $mail) use (&$plaintext) {
            $property = (new \ReflectionClass($mail))->getProperty('otpCode');
            $property->setAccessible(true);
            $plaintext = $property->getValue($mail);

            return is_string($plaintext) && preg_match('/^\d{6}$/', $plaintext) === 1;
        });
        $this->assertNotNull($plaintext);

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $plaintext])
            ->assertRedirect(route('chatbot.household.verification.email'));
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);

        // 31 + 88 = 119s from lock start → still locked.
        $this->travel(88)->seconds();
        $this->assertTrue($guard->isLocked((int) $account->account_id, (int) $request->request_id));

        $this->travel(2)->seconds();
        $this->assertFalse($guard->isLocked((int) $account->account_id, (int) $request->request_id));

        Mail::fake();
        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.household.verification.email'));

        $freshCode = null;
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class, function (HouseholdRecordVerificationOtpMail $mail) use (&$freshCode) {
            $property = (new \ReflectionClass($mail))->getProperty('otpCode');
            $property->setAccessible(true);
            $freshCode = $property->getValue($mail);

            return is_string($freshCode) && preg_match('/^\d{6}$/', $freshCode) === 1;
        });
        $this->assertNotNull($freshCode);

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $freshCode])
            ->assertRedirect(route('chatbot.household.information'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertNotNull($account->fresh()->resident_id);
    }

    public function test_four_wrong_then_correct_otp_succeeds_without_lock(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        [, $code] = $this->issueActiveEmailOtp($account, $request, '777777');

        for ($i = 0; $i < 4; $i++) {
            $this->post(route('chatbot.household.verification.email.verify'), ['otp' => '000000'])
                ->assertRedirect(route('chatbot.household.verification.email'));
        }

        $this->assertFalse(app(\App\Services\HouseholdEmailOtpAttemptGuard::class)
            ->isLocked((int) $account->account_id, (int) $request->request_id));

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.information'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertFalse(app(\App\Services\HouseholdEmailOtpAttemptGuard::class)
            ->isLocked((int) $account->account_id, (int) $request->request_id));
    }

    public function test_expired_otp_does_not_approve(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        [$otp, $code] = $this->issueActiveEmailOtp($account, $request);
        $otp->expires_at = now()->subMinute();
        $otp->save();

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($otp->fresh()->verified_at);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_reused_otp_cannot_verify_again(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        [$otp, $code] = $this->issueActiveEmailOtp($account, $request);

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.information'));

        $this->withSession(['resident_account_id' => $account->account_id])
            ->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.main'));

        $this->assertNotNull($otp->fresh()->verified_at);
        $this->assertSame(1, RecordRequestOtp::query()->whereNotNull('verified_at')->count());
    }

    public function test_otp_for_another_request_is_ignored(): void
    {
        $account = $this->actingAsResidentAccount();
        $older = $this->seedOlderAwaiting($account);
        [, , $latest] = $this->awaitingOtpSetup($account);
        $this->assertGreaterThan((int) $older->request_id, (int) $latest->request_id);

        [, $code] = $this->issueActiveEmailOtp($account, $older, '222222');

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $latest->fresh()->status);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertNull(RecordRequestOtp::query()->where('request_id', $older->request_id)->value('verified_at'));
    }

    public function test_foreign_account_cannot_verify_with_owner_otp(): void
    {
        [$owner, , $request] = $this->awaitingOtpSetup();
        [, $code] = $this->issueActiveEmailOtp($owner, $request, '333333');

        $viewer = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'viewer.verify@example.com',
        ]));
        $this->withSession(['resident_account_id' => $viewer->account_id])
            ->post(route('chatbot.household.verification.email.verify'), [
                'otp' => $code,
                'account_id' => $owner->account_id,
                'request_id' => $request->request_id,
            ])
            ->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($owner->fresh()->resident_id);
    }

    public function test_pending_and_denied_cannot_verify(): void
    {
        $account = $this->actingAsResidentAccount();

        foreach ([RecordRequest::STATUS_PENDING, RecordRequest::STATUS_DENIED] as $status) {
            RecordRequest::query()->delete();
            RecordRequestOtp::query()->delete();

            $row = new RecordRequest;
            $row->account_id = $account->account_id;
            $row->household_no_submitted = 'HH-001';
            $row->zone_submitted = '2';
            $row->relationship_submitted = 'Household Head';
            $row->first_name_submitted = 'Ana';
            $row->middle_name_submitted = 'Cruz';
            $row->last_name_submitted = 'Santos';
            $row->mobile_number_submitted = '09171234567';
            $row->email_submitted = $account->email;
            $row->submitter_ip = '127.0.0.1';
            $row->matched_resident_id = $status === RecordRequest::STATUS_DENIED ? null : 1;
            $row->status = $status;
            $row->decision_reason = null;
            $row->evaluated_at = now();
            $row->approved_at = null;
            $row->save();

            $this->post(route('chatbot.household.verification.email.verify'), ['otp' => '123456'])
                ->assertRedirect(route('chatbot.main'));
        }
    }

    public function test_conflicting_resident_link_fails_closed(): void
    {
        [$account, $resident, $request] = $this->awaitingOtpSetup();
        $other = Resident::factory()->create([
            'household_id' => $resident->household_id,
            'member_no' => 'MB-V-99',
            'first_name' => 'Other',
            'last_name' => 'Person',
        ]);
        $account->resident_id = $this->chatbotRelationshipKey($other);
        $account->save();
        [, $code] = $this->issueActiveEmailOtp($account->fresh(), $request);

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertSame((string) $this->chatbotRelationshipKey($other), (string) $account->fresh()->resident_id);
        $this->assertNull(RecordRequestOtp::query()->value('verified_at'));
    }

    public function test_missing_matched_resident_fails_closed(): void
    {
        [$account, , $request] = $this->awaitingOtpSetup();
        $request->matched_resident_id = null;
        $request->save();
        [, $code] = $this->issueActiveEmailOtp($account, $request);

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_missing_official_resident_fails_closed(): void
    {
        [$account, $resident, $request] = $this->awaitingOtpSetup();
        [, $code] = $this->issueActiveEmailOtp($account, $request);
        $resident->delete();

        $this->post(route('chatbot.household.verification.email.verify'), ['otp' => $code])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->fresh()->status);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertNull(RecordRequestOtp::query()->value('verified_at'));
    }

    public function test_awaiting_otp_cannot_access_household_before_verification(): void
    {
        $this->awaitingOtpSetup();

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_email_recipient_remains_authenticated_account_email(): void
    {
        Mail::fake();
        [$account, , $request] = $this->awaitingOtpSetup();

        $this->post(route('chatbot.household.verification.email.send'), [
            'email' => 'attacker@example.com',
        ])->assertRedirect(route('chatbot.household.verification.email'));

        Mail::assertSent(HouseholdRecordVerificationOtpMail::class, function ($mail) use ($account) {
            return $mail->hasTo($account->email);
        });
        $this->assertSame(1, RecordRequestOtp::query()->where('request_id', $request->request_id)->count());
    }

    public function test_sms_ui_is_primary_with_email_alternative(): void
    {
        $this->awaitingOtpSetup();

        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $sms = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $this->assertStringContainsString('SMS Verification', $sms);
        $this->assertStringContainsString('Try Other Way (Send via Email)', $sms);
        $this->assertStringContainsString(route('chatbot.household.verification.sms.verify'), $sms);
        $this->assertStringContainsString(route('chatbot.household.verification.sms.send'), $sms);
        $this->assertStringContainsString(route('chatbot.household.verification.email.send'), $sms);
        $this->assertStringNotContainsString('data-sms-paused', $sms);
        $this->assertStringNotContainsString('Verification Method', $sms);
    }
}
