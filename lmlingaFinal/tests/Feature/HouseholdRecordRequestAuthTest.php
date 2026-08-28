<?php

namespace Tests\Feature;

use App\Models\ResidentAccount;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HouseholdRecordRequestAuthTest extends TestCase
{
    use RefreshDatabase;

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
            'email' => 'ana.session@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    public function test_guest_is_redirected_to_chatbot_login(): void
    {
        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_valid_resident_session_can_open_request_page(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());

        $html = $this->withSession(['resident_account_id' => $account->account_id])
            ->get(route('chatbot.household.verification'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('name="householdNo"', $html);
        $this->assertStringContainsString('name="relationship"', $html);
        $this->assertStringContainsString('name="firstName"', $html);
        $this->assertStringContainsString('name="middleName"', $html);
        $this->assertStringContainsString('name="lastName"', $html);
        $this->assertStringContainsString('name="mobileNumber"', $html);
        $this->assertStringContainsString('name="emailAddress"', $html);
        $this->assertStringContainsString('data-lml-household-request-form', $html);
    }

    public function test_stale_resident_session_is_cleared_and_redirected_to_login(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $staleId = $account->account_id;
        $account->delete();

        $this->withSession(['resident_account_id' => $staleId])
            ->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.login'))
            ->assertSessionMissing('resident_account_id');
    }

    public function test_staff_admin_session_alone_does_not_authorize_request_page(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_public_chatbot_auth_pages_remain_unprotected(): void
    {
        $this->get(route('chatbot.landing'))->assertOk();
        $this->get(route('chatbot.login'))->assertOk();
        $this->get(route('chatbot.register'))->assertOk();
        $this->get(route('chatbot.password.request'))->assertOk();
        $this->get(route('chatbot.password.reset', [
            'token' => 'preview-token',
            'email' => 'ana.reset@example.com',
        ]))->assertOk();
    }
}
