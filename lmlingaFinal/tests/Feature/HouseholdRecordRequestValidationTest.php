<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdRecordRequestValidationTest extends TestCase
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
            'email' => 'ana.validation@example.com',
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
            'emailAddress' => 'ana.validation@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    private function assertNoRecordRequestInserted(): void
    {
        if (! Schema::hasTable('record_requests')) {
            return;
        }

        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_guest_cannot_post_request(): void
    {
        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.login'));

        $this->assertNoRecordRequestInserted();
    }

    public function test_valid_resident_post_redirects_to_status_after_insert(): void
    {
        $account = $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'))
            ->assertSessionMissing('errors');

        $this->assertTrue(Schema::hasTable('record_requests'));
        $this->assertDatabaseCount('record_requests', 1);
        $this->assertSame($account->resident_id, $account->fresh()->resident_id);
    }

    public function test_missing_household_no_is_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'householdNo' => '',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('householdNo');

        $this->assertNoRecordRequestInserted();
    }

    public function test_invalid_relationship_is_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'relationship' => 'Cousin',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('relationship');

        $this->assertNoRecordRequestInserted();
    }

    public function test_missing_first_name_is_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'firstName' => '',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('firstName');
    }

    public function test_missing_middle_name_is_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'middleName' => '',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('middleName');
    }

    public function test_missing_last_name_is_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'lastName' => '',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('lastName');
    }

    public function test_invalid_mobile_is_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'mobileNumber' => '0917123456',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('mobileNumber');

        $this->assertNoRecordRequestInserted();
    }

    public function test_valid_philippine_mobile_is_accepted(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'mobileNumber' => '09171234567',
            ]))
            ->assertRedirect(route('chatbot.main'))
            ->assertSessionMissing('errors');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'emailAddress' => 'not-an-email',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('emailAddress');
    }

    public function test_oversized_values_are_rejected(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'householdNo' => str_repeat('H', 51),
                'firstName' => str_repeat('A', 101),
                'middleName' => str_repeat('B', 101),
                'lastName' => str_repeat('C', 101),
                'emailAddress' => str_repeat('a', 140).'@example.com',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors([
                'householdNo',
                'firstName',
                'middleName',
                'lastName',
                'emailAddress',
            ]);

        $this->assertNoRecordRequestInserted();
    }

    public function test_browser_supplied_account_id_status_and_matched_resident_id_are_ignored(): void
    {
        $account = $this->actingAsResidentAccount(['resident_id' => null]);

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'account_id' => 999999,
                'resident_id' => 888888,
                'matched_resident_id' => 777777,
                'status' => 'Approved',
                'decision_reason' => 'forged',
                'evaluated_at' => '2026-01-01 00:00:00',
                'approved_at' => '2026-01-01 00:00:00',
            ]))
            ->assertRedirect(route('chatbot.main'))
            ->assertSessionMissing('errors');

        $this->assertDatabaseCount('record_requests', 1);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertDatabaseMissing('resident_accounts', [
            'account_id' => $account->account_id,
            'resident_id' => 888888,
        ]);
    }

    public function test_validation_failure_preserves_old_input_and_field_errors(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'householdNo' => '',
                'firstName' => 'Ana',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('householdNo')
            ->assertSessionHasInput('firstName', 'Ana');

        $html = $this->get(route('chatbot.household.verification'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('name="householdNo"', $html);
        $this->assertStringContainsString('value="Ana"', $html);
        $this->assertStringContainsString('hh-household-no-error', $html);
        $this->assertNoRecordRequestInserted();
    }

    public function test_linked_resident_id_is_not_changed(): void
    {
        $resident = Resident::factory()->create();
        $relationshipKey = $resident->getAttribute(Resident::resolvedPrimaryKeyName());
        $account = $this->actingAsResidentAccount([
            'email' => 'linked.validation@example.com',
            'resident_id' => $relationshipKey,
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'linked.validation@example.com',
            'resident_id' => 111,
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertSame($relationshipKey, $account->fresh()->resident_id);
        $this->assertDatabaseCount('record_requests', 1);
    }
}
