<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChatbotMainIdentityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function accountAttributes(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'zone_purok' => '2',
            'email' => 'juan.main@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    private function assertIdentityName(string $html, string $name): void
    {
        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__resident-name"\s*>\s*'.preg_quote($name, '/').'\s*</',
            $html
        );
        $this->assertStringContainsString('Hi, '.$name.'!', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__resident-name"\s*>\s*John Doe\s*</',
            $html
        );
        $this->assertStringNotContainsString('Hi, John Doe!', $html);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    public function test_valid_session_shows_account_full_name_in_sidebar_and_greeting(): void
    {
        $account = $this->actingAsResidentAccount();
        $updatedAt = $account->updated_at?->toJSON();

        $html = $this->get(route('chatbot.main', [
            'verification' => 'verified',
            'name' => 'Hacker Name',
            'account_id' => '999',
            'resident_id' => '888',
        ]))->assertOk()->getContent();

        $this->assertIdentityName($html, 'Juan Santos Dela Cruz');
        $this->assertStringNotContainsString('Hacker Name', $html);
        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*-\s*<\/span>/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH 123\s*<\/span>/s',
            $html
        );
        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);

        $this->assertSame($updatedAt, $account->fresh()->updated_at?->toJSON());
        $this->assertNull($account->fresh()->resident_id);
        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_blank_middle_name_does_not_create_double_spaces(): void
    {
        $this->actingAsResidentAccount([
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'email' => 'juan.nomiddle@example.com',
        ]);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Juan Dela Cruz', $html);
        $this->assertStringContainsString('Hi, Juan Dela Cruz!', $html);
        $this->assertStringNotContainsString('Juan  Dela Cruz', $html);
    }

    public function test_another_account_name_is_not_displayed(): void
    {
        ResidentAccount::query()->create($this->accountAttributes([
            'first_name' => 'Other',
            'middle_name' => 'Person',
            'last_name' => 'Account',
            'email' => 'other.main@example.com',
        ]));

        $this->actingAsResidentAccount([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'email' => 'ana.identity@example.com',
        ]);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertIdentityName($html, 'Ana Cruz Santos');
        $this->assertStringNotContainsString('Other Person Account', $html);
    }

    public function test_guest_main_does_not_show_john_doe(): void
    {
        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertIdentityName($html, 'Resident');
        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*-\s*<\/span>/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH 123\s*<\/span>/s',
            $html
        );
    }

    public function test_stale_session_does_not_show_john_doe(): void
    {
        $html = $this->withSession(['resident_account_id' => 999999])
            ->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();

        $this->assertIdentityName($html, 'Resident');
        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*-\s*<\/span>/s',
            $html
        );
    }

    public function test_pending_cta_shows_request_sent_with_real_name(): void
    {
        $account = $this->actingAsResidentAccount();

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = 'HH-151';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Juan';
        $row->middle_name_submitted = 'Santos';
        $row->last_name_submitted = 'Dela Cruz';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '203.0.113.10';
        $row->matched_resident_id = null;
        $row->status = RecordRequest::STATUS_PENDING;
        $row->save();

        $updatedAt = $row->fresh()->updated_at?->toJSON();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertIdentityName($html, 'Juan Santos Dela Cruz');
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString(route('chatbot.household.verification.status'), $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSame('Pending', $row->fresh()->status);
        $this->assertSame($updatedAt, $row->fresh()->updated_at?->toJSON());
        $this->assertDatabaseCount('record_requests', 1);
    }
}
