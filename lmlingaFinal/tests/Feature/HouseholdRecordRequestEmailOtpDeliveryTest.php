<?php

namespace Tests\Feature;

use App\Mail\HouseholdRecordVerificationOtpMail;
use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestEmailOtpDelivery;
use App\Support\HouseholdRecordRequestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HouseholdRecordRequestEmailOtpDeliveryTest extends TestCase
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
                'message_id' => 'iSms-EmailFlow',
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
            'email' => 'ana.email.otp@example.com',
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
            'emailAddress' => 'ana.email.otp@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));
        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
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

    private function seedAwaitingOtp(ResidentAccount $account): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = 'HH-001';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = 'forged.submitted@example.com';
        $row->submitter_ip = '203.0.113.40';
        $row->matched_resident_id = 17;
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = HouseholdRecordRequestMatcher::REASON_AWAITING_OTP;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    public function test_match_redirects_to_sms_ui_and_sends_one_sms_otp(): void
    {
        Mail::fake();
        $this->officialMatchResident();
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, RecordRequest::query()->value('status'));
        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);
        Mail::assertNothingSent();
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_email_otp_sends_to_authenticated_account_email_only(): void
    {
        Mail::fake();
        $account = $this->actingAsResidentAccount([
            'email' => 'owner.email.otp@example.com',
            'resident_id' => null,
        ]);
        $row = $this->seedAwaitingOtp($account);

        $response = $this->post(route('chatbot.household.verification.email.send'), [
            'email' => 'attacker@evil.example',
            'account_id' => '999',
            'request_id' => '999',
            'resident_id' => '999',
            'matched_resident_id' => '999',
            'status' => 'Approved',
        ]);

        $response->assertRedirect(route('chatbot.household.verification.email'));
        $response->assertSessionHas('household_otp_email_sent', true);

        Mail::assertSent(HouseholdRecordVerificationOtpMail::class, function (HouseholdRecordVerificationOtpMail $mail) {
            return $mail->hasTo('owner.email.otp@example.com')
                && $mail->hasSubject('LMLINGA Household Record Verification Code');
        });
        Mail::assertNotSent(HouseholdRecordVerificationOtpMail::class, function (HouseholdRecordVerificationOtpMail $mail) {
            return $mail->hasTo('attacker@evil.example')
                || $mail->hasTo('forged.submitted@example.com');
        });

        $this->assertDatabaseCount('record_request_otps', 1);
        $otp = RecordRequestOtp::query()->first();
        $this->assertNotNull($otp->last_sent_at);
        $this->assertNull($otp->verified_at);
        $this->assertNull($otp->invalidated_at);
        $this->assertTrue($otp->expires_at->between(now()->addMinutes(4), now()->addMinutes(6)));
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', (string) $otp->code_hash);

        $captured = null;
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class, function (HouseholdRecordVerificationOtpMail $mail) use (&$captured) {
            $html = $mail->render();
            preg_match('/verification code is:\s*<strong>(\d{6})<\/strong>/i', $html, $matches);
            $captured = $matches[1] ?? null;

            return $captured !== null
                && ! str_contains($html, 'matched_resident_id')
                && ! str_contains($html, 'request_id')
                && ! str_contains($html, 'account_id');
        });

        $this->assertNotNull($captured);
        $this->assertTrue(Hash::check($captured, $otp->code_hash));
        $this->assertDatabaseMissing('record_request_otps', ['code_hash' => $captured]);

        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->fresh()->status);
        $this->assertNull($row->fresh()->approved_at);
        $this->assertNull($account->fresh()->resident_id);
        Http::assertNothingSent();

        $html = $this->get(route('chatbot.household.verification.email'))->assertOk()->getContent();
        $this->assertStringContainsString("We've sent a 6-digit code to your email address", $html);
        $this->assertStringContainsString('ow******@example.com', $html);
        $this->assertStringContainsString('Resend Email', $html);
        $this->assertStringContainsString('Try Other Way (Send via SMS)', $html);
        $this->assertStringContainsString('The code will expire in', $html);
        $this->assertStringNotContainsString($captured, $html);
        $this->assertStringNotContainsString('owner.email.otp@example.com', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);

        $this->get(route('chatbot.household.verification.email'))->assertOk();
        $this->assertDatabaseCount('record_request_otps', 1);
        Mail::assertSentCount(1);
    }

    public function test_email_delivery_service_failure_keeps_awaiting_otp_and_allows_retry(): void
    {
        $account = $this->actingAsResidentAccount([
            'email' => 'fail2.email.otp@example.com',
        ]);
        $row = $this->seedAwaitingOtp($account);

        $mailManager = app('mail.manager');

        $pendingMail = \Mockery::mock();
        $pendingMail->shouldReceive('send')
            ->once()
            ->andThrow(new \RuntimeException('mail transport failed'));

        Mail::shouldReceive('to')
            ->once()
            ->with('fail2.email.otp@example.com')
            ->andReturn($pendingMail);

        $first = app(HouseholdRecordRequestEmailOtpDelivery::class)
            ->deliverForAwaitingRequest($account, $row);

        $this->assertFalse($first->sent);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->fresh()->status);
        $this->assertNull($row->fresh()->approved_at);
        $this->assertNotNull(RecordRequestOtp::query()->value('invalidated_at'));
        $this->assertNull(RecordRequestOtp::query()->value('last_sent_at'));

        Mail::swap($mailManager);
        Mail::fake();

        $retry = app(HouseholdRecordRequestEmailOtpDelivery::class)
            ->deliverForAwaitingRequest($account, $row->fresh());

        $this->assertTrue($retry->sent);
        $this->assertDatabaseCount('record_request_otps', 2);
        $this->assertNotNull(
            RecordRequestOtp::query()->whereNull('invalidated_at')->whereNotNull('last_sent_at')->first()
        );
        Mail::assertSent(HouseholdRecordVerificationOtpMail::class);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->fresh()->status);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_denied_pending_and_foreign_accounts_cannot_send_email_otp(): void
    {
        Mail::fake();
        Http::fake();

        $denied = $this->actingAsResidentAccount(['email' => 'denied.email@example.com']);
        $deniedRow = $this->seedAwaitingOtp($denied);
        $deniedRow->status = RecordRequest::STATUS_DENIED;
        $deniedRow->save();

        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.main'));
        Mail::assertNothingSent();
        $this->assertDatabaseCount('record_request_otps', 0);

        $pendingAccount = $this->actingAsResidentAccount(['email' => 'pending.email@example.com']);
        $pending = $this->seedAwaitingOtp($pendingAccount);
        $pending->status = RecordRequest::STATUS_PENDING;
        $pending->approved_at = null;
        $pending->save();

        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.main'));
        Mail::assertNothingSent();

        $none = $this->actingAsResidentAccount(['email' => 'none.email@example.com']);
        $this->post(route('chatbot.household.verification.email.send'))
            ->assertRedirect(route('chatbot.main'));
        Mail::assertNothingSent();
        $this->assertNull($none->fresh()->resident_id);

        $owner = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'owner.other@example.com',
        ]));
        $owned = $this->seedAwaitingOtp($owner);
        $viewer = $this->actingAsResidentAccount(['email' => 'viewer.other@example.com']);

        $result = app(HouseholdRecordRequestEmailOtpDelivery::class)
            ->deliverForAwaitingRequest($viewer, $owned);
        $this->assertFalse($result->sent);
        Mail::assertNothingSent();

        $this->post(route('chatbot.household.verification.email.send'), [
            'request_id' => $owned->request_id,
            'account_id' => $owner->account_id,
            'email' => $owner->email,
        ])->assertRedirect(route('chatbot.main'));
        Mail::assertNothingSent();
    }

    public function test_sms_try_other_way_posts_email_send_and_delivers_otp(): void
    {
        Mail::fake();

        $account = $this->actingAsResidentAccount([
            'email' => 'sms.alt.email@example.com',
        ]);
        $this->seedAwaitingOtp($account);

        $smsHtml = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $this->assertStringContainsString('Try Other Way (Send via Email)', $smsHtml);
        $this->assertStringContainsString(route('chatbot.household.verification.email.send'), $smsHtml);
        $this->assertStringNotContainsString('data-lml-otp-alternative', $smsHtml);
        $this->assertDatabaseCount('record_request_otps', 0);
        Mail::assertNothingSent();

        $this->get(route('chatbot.household.verification.email'))->assertOk();
        $this->assertDatabaseCount('record_request_otps', 0);
        Mail::assertNothingSent();

        $this->post(route('chatbot.household.verification.email.send'), [
            'email' => 'attacker.injected@example.com',
        ])
            ->assertRedirect(route('chatbot.household.verification.email'));

        $this->assertDatabaseCount('record_request_otps', 1);
        $otp = RecordRequestOtp::query()->first();
        $this->assertNotNull($otp->last_sent_at);
        $this->assertNull($otp->invalidated_at);

        Mail::assertSent(HouseholdRecordVerificationOtpMail::class, function (HouseholdRecordVerificationOtpMail $mail) use ($account) {
            return $mail->hasTo($account->email)
                && ! $mail->hasTo('attacker.injected@example.com')
                && ! $mail->hasTo('forged.submitted@example.com');
        });

        $emailHtml = $this->get(route('chatbot.household.verification.email'))->assertOk()->getContent();
        $this->assertStringContainsString('Email Verification', $emailHtml);
        $this->assertStringContainsString('Resend Email', $emailHtml);
        $this->assertStringContainsString(route('chatbot.household.verification.email.send'), $emailHtml);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, RecordRequest::query()->value('status'));
        $this->assertNull($account->fresh()->resident_id);
        Http::assertNothingSent();
    }

    public function test_method_route_redirects_to_sms_and_main_continues_on_sms(): void
    {
        Mail::fake();
        $account = $this->actingAsResidentAccount();
        $this->seedAwaitingOtp($account);

        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $sms = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $this->assertStringContainsString('SMS Verification', $sms);
        $this->assertStringContainsString('Try Other Way (Send via Email)', $sms);
        $this->assertStringContainsString(route('chatbot.household.verification.email.send'), $sms);
        $this->assertStringNotContainsString('Verification Method', $sms);

        $main = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Continue Verification', $main);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $main);
    }
}
