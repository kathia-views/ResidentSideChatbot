<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Tests\TestCase;

class HouseholdRequestVerificationUiTest extends TestCase
{
    public function test_resident_request_form_describes_automatic_verification(): void
    {
        $html = $this->get(route('chatbot.household.verification'))->assertOk()->getContent();

        $this->assertStringContainsString('automatically compare', $html);
        $this->assertStringContainsString('Submit Request', $html);
        $this->assertStringContainsString('chatbot/household/verification/status', $html);
        $this->assertStringNotContainsString('reviewed before your identity', $html);
    }

    public function test_daily_limit_state_hides_submit_action(): void
    {
        $html = $this->get(route('chatbot.household.verification', ['state' => 'daily-limit']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Daily request limit reached', $html);
        $this->assertStringNotContainsString('data-lml-household-request-form', $html);
        $this->assertStringNotContainsString('>Submit Request</', $html);
    }

    public function test_status_page_renders_automatic_verification_states(): void
    {
        $states = [
            'verifying' => 'Verification in progress',
            'approved' => 'Match found',
            'rejected' => 'No match found',
            'failed-1' => 'Failed attempt 1 of 3',
            'failed-2' => 'Failed attempt 2 of 3',
            'failed-3' => 'Failed attempt 3 of 3',
            'daily-limit' => 'Daily request limit reached',
        ];

        foreach ($states as $state => $label) {
            $html = $this->get(route('chatbot.household.verification.status', ['state' => $state]))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString($label, $html);
            $this->assertStringNotContainsString('Skip', $html);
        }

        $blocked = $this->get(route('chatbot.household.verification.status', ['state' => 'daily-limit']))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('Try again', $blocked);
        $this->assertStringContainsString('Submit Request is unavailable', $blocked);
    }

    public function test_admin_household_requests_remain_monitoring_without_manual_approve(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Monitor automatic household record verification', $html);
        $this->assertStringNotContainsString('Approve request', $html);
        $this->assertStringNotContainsString('Reject request', $html);
    }
}
