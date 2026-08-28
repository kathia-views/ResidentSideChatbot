<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HouseholdRecordRequestPersistenceTest extends TestCase
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
            'email' => 'ana.request@example.com',
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
            'emailAddress' => 'ana.request@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedRequest(ResidentAccount $account, string $status, array $overrides = []): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = $overrides['household_no_submitted'] ?? 'HH-OLD';
        $row->zone_submitted = $overrides['zone_submitted'] ?? '2';
        $row->relationship_submitted = $overrides['relationship_submitted'] ?? 'Spouse';
        $row->first_name_submitted = $overrides['first_name_submitted'] ?? 'Old';
        $row->middle_name_submitted = $overrides['middle_name_submitted'] ?? 'Name';
        $row->last_name_submitted = $overrides['last_name_submitted'] ?? 'Row';
        $row->mobile_number_submitted = $overrides['mobile_number_submitted'] ?? '09170000000';
        $row->email_submitted = $overrides['email_submitted'] ?? $account->email;
        $row->submitter_ip = '198.51.100.10';
        $row->matched_resident_id = $overrides['matched_resident_id'] ?? null;
        $row->status = $status;
        $row->decision_reason = $overrides['decision_reason'] ?? null;
        $row->evaluated_at = null;
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    public function test_sqlite_has_record_requests_table_representation(): void
    {
        $this->assertTrue(Schema::hasTable('record_requests'));
    }

    public function test_valid_post_creates_exactly_one_pending_record_request(): void
    {
        $account = $this->actingAsResidentAccount();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 1);

        $row = RecordRequest::query()->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $account->account_id, (int) $row->account_id);
        $this->assertSame('HH-151', $row->household_no_submitted);
        $this->assertSame('2', $row->zone_submitted);
        $this->assertSame('Household Head', $row->relationship_submitted);
        $this->assertSame('Ana', $row->first_name_submitted);
        $this->assertSame('Cruz', $row->middle_name_submitted);
        $this->assertSame('Santos', $row->last_name_submitted);
        $this->assertSame('09171234567', $row->mobile_number_submitted);
        $this->assertSame('ana.request@example.com', $row->email_submitted);
        $this->assertSame('203.0.113.10', $row->submitter_ip);
        $this->assertNull($row->matched_resident_id);
        $this->assertSame('Pending', $row->status);
        $this->assertSame(RecordRequest::STATUS_PENDING, $row->status);
        $this->assertNull($row->decision_reason);
        $this->assertNull($row->evaluated_at);
        $this->assertNull($row->approved_at);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_successful_insert_redirects_to_main_with_request_sent(): void
    {
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $html = $this->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString('Request Household Record', $html);
        $this->assertStringNotContainsString(route('chatbot.household.verification.status'), $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.verification')).'"', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString(route('chatbot.household.information'), $html);
        $this->assertSame('Pending', RecordRequest::query()->value('status'));
        $this->assertNull(RecordRequest::query()->value('matched_resident_id'));
    }

    public function test_browser_posted_status_account_id_and_matched_resident_id_are_ignored(): void
    {
        $owner = $this->actingAsResidentAccount();
        $other = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'other.request@example.com',
        ]));

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'account_id' => (string) $other->account_id,
            'status' => 'Approved',
            'matched_resident_id' => '99',
            'zone_submitted' => '9',
            'decision_reason' => 'forged',
            'evaluated_at' => '2026-01-01 00:00:00',
            'approved_at' => '2026-01-01 00:00:00',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 1);

        $row = RecordRequest::query()->first();
        $this->assertSame((int) $owner->account_id, (int) $row->account_id);
        $this->assertSame('Pending', $row->status);
        $this->assertSame('2', $row->zone_submitted);
        $this->assertNull($row->matched_resident_id);
        $this->assertNull($row->decision_reason);
        $this->assertNull($row->evaluated_at);
        $this->assertNull($row->approved_at);
        $this->assertNull($owner->fresh()->resident_id);
    }

    public function test_validation_failure_creates_no_row(): void
    {
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'householdNo' => '',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('householdNo');

        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_resident_id_and_official_residents_remain_unchanged(): void
    {
        $resident = Resident::factory()->create();
        $residentCount = Resident::query()->count();
        $householdCount = Household::query()->count();

        $relationshipKey = $resident->getAttribute(Resident::resolvedPrimaryKeyName());
        $account = $this->actingAsResidentAccount([
            'email' => 'linked.request@example.com',
            'resident_id' => $relationshipKey,
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'linked.request@example.com',
            'resident_id' => 111,
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertSame($relationshipKey, $account->fresh()->resident_id);
        $this->assertSame($residentCount, Resident::query()->count());
        $this->assertSame($householdCount, Household::query()->count());
        $this->assertDatabaseHas('residents', [
            $resident->getKeyName() => $resident->getKey(),
        ]);
    }

    public function test_query_string_approved_cannot_grant_household_access(): void
    {
        $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload());

        $html = $this->get(route('chatbot.main', ['state' => 'approved']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString('Continue to Household Information', $html);
        $this->assertStringNotContainsString(route('chatbot.household.information'), $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSame('Pending', RecordRequest::query()->value('status'));
    }

    public function test_blank_zone_purok_does_not_invent_zone_submitted(): void
    {
        $this->actingAsResidentAccount(['zone_purok' => null]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertStatus(422);

        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_second_valid_submit_while_pending_does_not_create_another_row(): void
    {
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $first = RecordRequest::query()->first();
        $this->assertNotNull($first);
        $original = $first->only([
            'request_id',
            'account_id',
            'household_no_submitted',
            'zone_submitted',
            'relationship_submitted',
            'first_name_submitted',
            'middle_name_submitted',
            'last_name_submitted',
            'mobile_number_submitted',
            'email_submitted',
            'submitter_ip',
            'matched_resident_id',
            'status',
            'decision_reason',
            'evaluated_at',
            'approved_at',
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'householdNo' => 'HH-999',
            'relationship' => 'Son',
            'firstName' => 'Changed',
            'middleName' => 'Name',
            'lastName' => 'Here',
            'mobileNumber' => '09179999999',
            'emailAddress' => 'changed.request@example.com',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 1);
        $this->assertSame(1, RecordRequest::query()->where('status', RecordRequest::STATUS_PENDING)->count());

        $fresh = $first->fresh();
        foreach ($original as $column => $value) {
            $this->assertEquals($value, $fresh->{$column}, $column.' must remain unchanged');
        }

        $html = $this->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);

        $this->assertNull($account->fresh()->resident_id);
        $this->assertNull($fresh->matched_resident_id);
        $this->assertSame('Pending', $fresh->status);
    }

    public function test_another_resident_account_can_create_its_own_pending_request(): void
    {
        $first = $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $second = $this->actingAsResidentAccount([
            'email' => 'second.request@example.com',
            'zone_purok' => '3',
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'second.request@example.com',
            'householdNo' => 'HH-200',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 2);
        $this->assertSame(1, RecordRequest::query()->where('account_id', $first->account_id)->where('status', 'Pending')->count());
        $this->assertSame(1, RecordRequest::query()->where('account_id', $second->account_id)->where('status', 'Pending')->count());
    }

    public function test_no_match_history_does_not_block_a_new_pending_request(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_NO_MATCH);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 2);
        $this->assertSame(1, RecordRequest::query()->where('account_id', $account->account_id)->where('status', 'Pending')->count());
        $this->assertSame(1, RecordRequest::query()->where('account_id', $account->account_id)->where('status', RecordRequest::STATUS_NO_MATCH)->count());
    }

    public function test_denied_history_does_not_block_a_new_pending_request(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_DENIED);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 2);
        $this->assertSame(1, RecordRequest::query()->where('account_id', $account->account_id)->where('status', 'Pending')->count());
        $this->assertSame(1, RecordRequest::query()->where('account_id', $account->account_id)->where('status', RecordRequest::STATUS_DENIED)->count());
    }

    public function test_forged_account_id_cannot_bypass_duplicate_pending_rule(): void
    {
        $owner = $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload());

        $other = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'forged.target@example.com',
        ]));

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'account_id' => (string) $other->account_id,
            'householdNo' => 'HH-FORGED',
        ]))->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 1);
        $this->assertSame(0, RecordRequest::query()->where('account_id', $other->account_id)->count());
        $row = RecordRequest::query()->first();
        $this->assertSame((int) $owner->account_id, (int) $row->account_id);
        $this->assertSame('HH-151', $row->household_no_submitted);
        $this->assertSame('Pending', $row->status);
    }

    public function test_admin_household_requests_list_real_record_requests_not_demo_catalog(): void
    {
        $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload());

        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Household Requests', $html);
        $this->assertStringContainsString('Ana Cruz Santos', $html);
        $this->assertStringContainsString('HH-151', $html);
        $this->assertStringNotContainsString('Kristine Mendoza Reyes', $html);
        $this->assertDatabaseCount('record_requests', 1);
    }
}
