<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Support\HouseholdRecordRequestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HouseholdOtpVerificationUiRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAwaitingAccount(): ResidentAccount
    {
        $account = ResidentAccount::query()->create([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '2',
            'email' => 'john.doe@gmail.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ]);
        $this->withSession(['resident_account_id' => $account->account_id]);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = 'HH-001';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234789';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '127.0.0.1';
        $row->matched_resident_id = 17;
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = HouseholdRecordRequestMatcher::REASON_AWAITING_OTP;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        return $account->fresh();
    }

    public function test_email_verification_ui_matches_authoritative_screenshot_structure(): void
    {
        $this->actingAsAwaitingAccount();

        $html = $this->get(route('chatbot.household.verification.email'))->assertOk()->getContent();

        $this->assertStringContainsString('lml-chatbot-sms-verify__back', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.verification.otp-method')).'"', $html);
        $this->assertStringContainsString('lml-chatbot-sms-verify__card', $html);
        $this->assertStringContainsString('bi-envelope-check-fill', $html);
        $this->assertStringContainsString('Email Verification', $html);
        $this->assertStringContainsString("We've sent a 6-digit code to your email address", $html);
        $this->assertStringContainsString('jo******@gmail.com', $html);
        $this->assertStringContainsString(
            'Enter the 6-digit code below to verify your identity and access the household record.',
            $html
        );
        $this->assertSame(6, substr_count($html, 'data-lml-otp-digit'));
        $this->assertStringContainsString('lml-chatbot-sms-verify__verify-btn', $html);
        $this->assertMatchesRegularExpression('/>\s*Verify\s*</', $html);
        $this->assertStringContainsString('The code will expire in', $html);
        $this->assertStringNotContainsString('The code will expire in:', $html);
        $this->assertStringContainsString("Didn't receive a code?", $html);
        $this->assertStringContainsString('Resend Email', $html);
        $this->assertStringContainsString(route('chatbot.household.verification.email.send'), $html);
        $this->assertStringContainsString('>OR</span>', $html);
        $this->assertStringContainsString('Try Other Way (Send via SMS)', $html);
        $this->assertStringContainsString('bi-chat-dots-fill', $html);
        $this->assertStringContainsString(route('chatbot.household.verification.email.verify'), $html);
        $this->assertStringContainsString('name="otp"', $html);
        $this->assertStringContainsString('data-lml-otp-server-submit="true"', $html);
        $this->assertStringNotContainsString('Resend OTP', $html);
        $this->assertStringNotContainsString('Choose verification method', $html);
    }

    public function test_sms_verification_ui_matches_authoritative_screenshot_structure(): void
    {
        $this->actingAsAwaitingAccount();

        $html = $this->get(route('chatbot.household.verification.sms'))->assertOk()->getContent();
        $emailHtml = $this->get(route('chatbot.household.verification.email'))->assertOk()->getContent();

        foreach ([
            'lml-chatbot-sms-verify__back',
            'lml-chatbot-sms-verify__card',
            'lml-chatbot-sms-verify__hero-icon',
            'lml-chatbot-sms-verify__title',
            'lml-chatbot-sms-verify__otp-input',
            'lml-chatbot-sms-verify__verify-btn',
            'lml-chatbot-sms-verify__timer',
            'lml-chatbot-sms-verify__resend',
            'lml-chatbot-sms-verify__divider',
            'lml-chatbot-sms-verify__alt-btn',
        ] as $sharedClass) {
            $this->assertStringContainsString($sharedClass, $html);
            $this->assertStringContainsString($sharedClass, $emailHtml);
        }

        $this->assertStringContainsString('bi-shield-lock-fill', $html);
        $this->assertStringContainsString('SMS Verification', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.main')).'"', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.verification.otp-method')).'"', $html);
        $this->assertStringContainsString("We've sent a 6-digit OTP to your mobile number", $html);
        $this->assertStringContainsString('09******789', $html);
        $this->assertStringContainsString(
            'Enter the 6-digit code below to verify your identity and access the household record.',
            $html
        );
        $this->assertSame(6, substr_count($html, 'data-lml-otp-digit'));
        $this->assertSame(6, substr_count($emailHtml, 'data-lml-otp-digit'));
        $this->assertMatchesRegularExpression('/>\s*Verify\s*</', $html);
        $this->assertStringContainsString('The code will expire in', $html);
        $this->assertStringNotContainsString('The code will expire in:', $html);
        $this->assertStringContainsString('Resend OTP', $html);
        $this->assertStringContainsString(route('chatbot.household.verification.sms.send'), $html);
        $this->assertStringContainsString(route('chatbot.household.verification.sms.verify'), $html);
        $this->assertStringContainsString('name="otp"', $html);
        $this->assertStringContainsString('data-lml-otp-server-submit="true"', $html);
        $this->assertStringContainsString('data-lml-otp-resend-server', $html);
        $this->assertStringNotContainsString('data-sms-paused="true"', $html);
        $this->assertStringNotContainsString('data-lml-otp-resend-locked', $html);
        $this->assertStringContainsString('>OR</span>', $html);
        $this->assertStringContainsString('Try Other Way (Send via Email)', $html);
        $this->assertStringContainsString('bi-envelope-fill', $html);
        $this->assertStringContainsString(route('chatbot.household.verification.email.send'), $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringNotContainsString('data-lml-otp-alternative', $html);
        $this->assertStringNotContainsString('data-alternative-url=', $html);
        $this->assertStringNotContainsString('Verification Method', $html);
        $this->assertStringNotContainsString('Send code by SMS (unavailable)', $html);
        $this->assertStringNotContainsString('SMS verification is temporarily paused.', $html);
        $this->assertStringNotContainsString('Send code by Email (', $html);
        $this->assertStringNotContainsString('Resend Email', $html);
        $this->assertStringNotContainsString('Choose verification method', $html);
        $this->assertStringNotContainsString('Choose how you want to receive your verification code.', $html);
    }
}
