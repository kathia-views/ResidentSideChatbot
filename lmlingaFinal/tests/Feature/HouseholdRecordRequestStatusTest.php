<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HouseholdRecordRequestStatusTest extends TestCase
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
            'email' => 'ana.status@example.com',
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
            'householdNo' => 'HH-151',
            'relationship' => 'Household Head',
            'firstName' => 'Ana',
            'middleName' => 'Cruz',
            'lastName' => 'Santos',
            'mobileNumber' => '09171234567',
            'emailAddress' => 'ana.status@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    public function test_guest_cannot_access_status_page(): void
    {
        $this->get(route('chatbot.household.verification.status'))
            ->assertRedirect(route('chatbot.login'));

        $this->get(route('chatbot.household.verification.status', ['state' => 'approved']))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_authenticated_pending_request_shows_verifying_ui_from_database(): void
    {
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame('Pending', $row->status);
        $updatedAt = $row->updated_at?->toJSON();
        $matched = $row->matched_resident_id;
        $residentId = $account->resident_id;

        $html = $this->get(route('chatbot.household.verification.status'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Verification in progress', $html);
        $this->assertStringContainsString('compared with barangay records', $html);
        $this->assertStringNotContainsString('Match found', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('Continue to Household Information', $html);
        $this->assertStringNotContainsString(route('chatbot.household.information'), $html);

        $fresh = $row->fresh();
        $this->assertSame('Pending', $fresh->status);
        $this->assertSame($updatedAt, $fresh->updated_at?->toJSON());
        $this->assertSame($matched, $fresh->matched_resident_id);
        $this->assertNull($fresh->matched_resident_id);
        $this->assertSame($residentId, $account->fresh()->resident_id);
        $this->assertDatabaseCount('record_requests', 1);
    }

    public function test_query_string_approved_does_not_override_real_pending_status(): void
    {
        $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload());

        $html = $this->get(route('chatbot.household.verification.status', [
            'state' => 'approved',
            'request_id' => '999',
            'account_id' => '999',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Verification in progress', $html);
        $this->assertStringNotContainsString('Match found', $html);
        $this->assertStringNotContainsString('Access was automatically approved', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('Continue to Household Information', $html);
        $this->assertSame('Pending', RecordRequest::query()->first()->status);
    }

    public function test_account_without_request_is_redirected_to_request_form(): void
    {
        $this->actingAsResidentAccount();

        $this->get(route('chatbot.household.verification.status'))
            ->assertRedirect(route('chatbot.household.verification'));
    }

    public function test_another_account_cannot_inspect_current_account_request(): void
    {
        $owner = $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload());
        $ownerRowId = RecordRequest::query()->value('request_id');

        $intruder = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'intruder.status@example.com',
        ]));

        $this->withSession(['resident_account_id' => $intruder->account_id])
            ->get(route('chatbot.household.verification.status', [
                'request_id' => $ownerRowId,
                'account_id' => $owner->account_id,
            ]))
            ->assertRedirect(route('chatbot.household.verification'));

        $this->assertSame(1, RecordRequest::query()->where('account_id', $owner->account_id)->count());
        $this->assertSame(0, RecordRequest::query()->where('account_id', $intruder->account_id)->count());
    }

    public function test_duplicate_pending_submit_redirects_to_main(): void
    {
        $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload());

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'householdNo' => 'HH-999',
        ]))->assertRedirect(route('chatbot.main'));

        $html = $this->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();

        $this->assertDatabaseCount('record_requests', 1);
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString('Continue to Household Information', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
    }
}
