<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HouseholdRequestVerificationUiTest extends TestCase
{
    use RefreshDatabase;

    private function asResident(): self
    {
        $account = ResidentAccount::query()->create([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '1',
            'email' => 'ana.ui.'.uniqid('', true).'@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ]);

        return $this->withSession(['resident_account_id' => $account->account_id]);
    }

    public function test_resident_request_form_describes_automatic_verification(): void
    {
        $html = $this->asResident()->get(route('chatbot.household.verification'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('automatically compare', $html);
        $this->assertStringContainsString('Matching is automatic', $html);
        $this->assertStringContainsString('not a manual Admin approval step', $html);
        $this->assertStringContainsString('Submit Request', $html);
        $this->assertStringContainsString(route('chatbot.household.verification.store'), $html);
        $this->assertStringNotContainsString('reviewed before your identity', $html);
    }

    public function test_daily_limit_state_hides_submit_action(): void
    {
        $html = $this->asResident()
            ->get(route('chatbot.household.verification', ['state' => 'daily-limit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Daily request limit reached', $html);
        $this->assertStringContainsString('maximum number of household record requests allowed today', $html);
        $this->assertStringNotContainsString('data-lml-household-request-form', $html);
        $this->assertStringNotContainsString('>Submit Request</', $html);
    }

    public function test_status_page_uses_real_pending_request_not_query_string(): void
    {
        $account = ResidentAccount::query()->create([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '1',
            'email' => 'ana.ui.status@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ]);

        $this->withSession(['resident_account_id' => $account->account_id])
            ->post(route('chatbot.household.verification.store'), [
                'householdNo' => 'HH-151',
                'relationship' => 'Household Head',
                'firstName' => 'Ana',
                'middleName' => 'Cruz',
                'lastName' => 'Santos',
                'mobileNumber' => '09171234567',
                'emailAddress' => 'ana.ui.status@example.com',
            ]);

        $html = $this->get(route('chatbot.household.verification.status', ['state' => 'approved']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Verification in progress', $html);
        $this->assertStringContainsString('compared with barangay records', $html);
        $this->assertStringContainsString('does not require Admin review', $html);
        $this->assertStringContainsString('automatic verification', strtolower($html));
        $this->assertStringNotContainsString('Match found', $html);
        $this->assertStringNotContainsString('Continue to Household Information', $html);
        $this->assertStringNotContainsString('>Skip</', $html);
        $this->assertStringNotContainsString('Approve', $html);
        $this->assertStringNotContainsString('Reject request', $html);
    }

    public function test_admin_household_requests_remain_monitoring_without_manual_approve(): void
    {
        $account = ResidentAccount::query()->create([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '1',
            'email' => 'ana.ui.list@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ]);
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = 'HH-151';
        $row->zone_submitted = '1';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '203.0.113.10';
        $row->status = RecordRequest::STATUS_PENDING;
        $row->save();

        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Household Requests', $html);
        $this->assertStringContainsString('Monitor automatic household record verification history and results.', $html);
        $this->assertStringContainsString('>View</', $html);
        $this->assertStringNotContainsString('Approve request', $html);
        $this->assertStringNotContainsString('Reject request', $html);
        $this->assertStringNotContainsString('Manual Review', $html);
        $this->assertStringNotContainsString('>Verify</', $html);
        $this->assertStringNotContainsString('enforced by the backend later', $html);
    }

    public function test_admin_household_request_details_use_automatic_verification_semantics(): void
    {
        $account = ResidentAccount::query()->create([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '1',
            'email' => 'ana.ui.details@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ]);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = 'HH-151';
        $row->zone_submitted = '1';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '203.0.113.10';
        $row->matched_resident_id = null;
        $row->status = RecordRequest::STATUS_PENDING;
        $row->decision_reason = null;
        $row->evaluated_at = null;
        $row->approved_at = null;
        $row->save();

        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.view', ['id' => 'rr-'.$row->request_id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Automatic verification result for this household record access request.', $html);
        $this->assertStringContainsString('Verification result for', $html);
        $this->assertStringContainsString('Automatic verification result', $html);
        $this->assertStringContainsString('Verification Result', $html);
        $this->assertStringContainsString('Back to Household Requests', $html);
        $this->assertStringContainsString('Exit', $html);
        $this->assertStringNotContainsString('Review result for', $html);
        $this->assertStringNotContainsString('>Decision</', $html);
        $this->assertStringNotContainsString('Manual Review', $html);
        $this->assertStringNotContainsString('Approve request', $html);
        $this->assertStringNotContainsString('Reject request', $html);
        $this->assertStringNotContainsString('>Approve</', $html);
        $this->assertStringNotContainsString('>Reject</', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Approve\s*<\/button>/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Reject\s*<\/button>/i', $html);
    }

    public function test_guest_cannot_open_household_information_without_chatbot_session(): void
    {
        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_layouts_expose_skip_to_main_content_link(): void
    {
        $household = \App\Models\Household::factory()->create(['household_no' => '151']);
        $resident = \App\Models\Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'relation' => 'Head',
            'birthday' => now()->subYears(30)->toDateString(),
            'sex' => 'Female',
        ]);
        $residentKey = $resident->getAttribute(\App\Models\Resident::resolvedPrimaryKeyName());

        $account = ResidentAccount::query()->create([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '1',
            'email' => 'ana.skip.'.uniqid('', true).'@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => $residentKey,
        ]);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = '151';
        $row->zone_submitted = '1';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '127.0.0.1';
        $row->matched_resident_id = $residentKey;
        $row->status = RecordRequest::STATUS_APPROVED;
        $row->decision_reason = null;
        $row->evaluated_at = now();
        $row->approved_at = now();
        $row->save();

        $otp = new \App\Models\RecordRequestOtp;
        $otp->request_id = $row->request_id;
        $otp->code_hash = Hash::make('123456');
        $otp->destination_fingerprint = app(\App\Services\HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(\App\Services\HouseholdRecordRequestOtpIssuer::DEST_EMAIL, (string) $account->email);
        $otp->expires_at = now()->addMinutes(5);
        $otp->attempt_count = 1;
        $otp->resend_count = 0;
        $otp->last_sent_at = now();
        $otp->verified_at = now();
        $otp->invalidated_at = null;
        $otp->save();

        $chatbotHtml = $this->withSession(['resident_account_id' => $account->account_id])
            ->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();
        $adminHtml = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        foreach ([$chatbotHtml, $adminHtml] as $html) {
            $this->assertMatchesRegularExpression(
                '/<a[^>]*href="#main-content"[^>]*>\s*Skip to main content\s*<\/a>/i',
                $html
            );
            $this->assertStringContainsString('id="main-content"', $html);
            $this->assertStringContainsString('lml-skip-link', $html);
        }
    }
}
