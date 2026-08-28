<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Support\HouseholdRecordRequestUiCatalog;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminHouseholdRequestMonitoringTest extends TestCase
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
            'email' => 'ana.admin.hr@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedRequest(ResidentAccount $account, string $status, array $overrides = []): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = $overrides['household_no_submitted'] ?? 'HH-151';
        $row->zone_submitted = $overrides['zone_submitted'] ?? '2';
        $row->relationship_submitted = $overrides['relationship_submitted'] ?? 'Household Head';
        $row->first_name_submitted = $overrides['first_name_submitted'] ?? 'Ana';
        $row->middle_name_submitted = $overrides['middle_name_submitted'] ?? 'Cruz';
        $row->last_name_submitted = $overrides['last_name_submitted'] ?? 'Santos';
        $row->mobile_number_submitted = $overrides['mobile_number_submitted'] ?? '09171234567';
        $row->email_submitted = $overrides['email_submitted'] ?? $account->email;
        $row->submitter_ip = '198.51.100.20';
        $row->matched_resident_id = $overrides['matched_resident_id'] ?? null;
        $row->status = $status;
        $row->decision_reason = $overrides['decision_reason'] ?? null;
        $row->evaluated_at = $overrides['evaluated_at'] ?? null;
        $row->approved_at = $overrides['approved_at'] ?? null;
        $row->save();

        return $row->fresh();
    }

    private function asAdmin(): self
    {
        return $this->withSession([UiRole::SESSION_KEY => 'admin']);
    }

    public function test_admin_can_open_household_requests(): void
    {
        $this->asAdmin()
            ->get(route('household-requests.index'))
            ->assertOk()
            ->assertSee('Household Requests')
            ->assertSee('Monitor automatic household record verification history and results.', false);
    }

    public function test_empty_record_requests_shows_empty_state_without_demo_rows(): void
    {
        $html = $this->asAdmin()
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('No household requests match your search, zone, or status filters.', $html);
        $this->assertStringNotContainsString('Kristine Mendoza Reyes', $html);
        $this->assertStringNotContainsString('Melanie Arguza Javier', $html);
        $this->assertStringNotContainsString('kristine.reyes@email.com', $html);
        $this->assertDatabaseCount('record_requests', 0);
        $this->assertDatabaseCount('residents', 0);
        $this->assertDatabaseCount('households', 0);
    }

    public function test_list_shows_real_record_request_snapshot_fields(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $row = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'first_name_submitted' => 'Liza',
            'middle_name_submitted' => '',
            'last_name_submitted' => 'Reyes',
            'household_no_submitted' => 'HH-REAL-01',
            'zone_submitted' => '4',
            'mobile_number_submitted' => '09180001122',
            'email_submitted' => 'liza.hr@example.com',
            'approved_at' => now(),
        ]);

        $html = $this->asAdmin()
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Liza Reyes', $html);
        $this->assertStringNotContainsString('Liza  Reyes', $html);
        $this->assertStringContainsString('HH-REAL-01', $html);
        $this->assertStringContainsString('Zone 4', $html);
        $this->assertStringContainsString('Approved', $html);
        $this->assertStringContainsString($row->created_at->format('F j, Y'), $html);
        $this->assertStringContainsString('data-hr-household="HH-REAL-01"', $html);
        $this->assertStringContainsString('data-hr-email="liza.hr@example.com"', $html);
        $this->assertStringContainsString('data-hr-mobile="09180001122"', $html);
        $this->assertStringContainsString('data-hr-status="Approved"', $html);
        $this->assertStringContainsString('href="'.e(route('household-requests.view', ['id' => HouseholdRecordRequestUiCatalog::publicId((int) $row->request_id)])).'"', $html);
        $this->assertStringNotContainsString('Kristine Mendoza Reyes', $html);
        $this->assertStringNotContainsString('submitter_ip', $html);
        $this->assertStringNotContainsString('matched_resident_id', $html);
        $this->assertDatabaseCount('residents', 0);
        $this->assertDatabaseCount('households', 0);
    }

    public function test_status_filter_options_use_record_request_statuses(): void
    {
        $html = $this->asAdmin()
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        foreach (HouseholdRecordRequestUiCatalog::ALLOWED_STATUSES as $status) {
            $this->assertStringContainsString('>'.$status.'</option>', $html);
        }
        $this->assertStringNotContainsString('>Rejected</option>', $html);
    }

    public function test_pending_and_denied_statuses_render_without_rewriting(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->seedRequest($account, RecordRequest::STATUS_PENDING, [
            'first_name_submitted' => 'Pending',
            'last_name_submitted' => 'Person',
            'email_submitted' => 'pending.hr@example.com',
        ]);
        $this->seedRequest($account, RecordRequest::STATUS_DENIED, [
            'first_name_submitted' => 'Denied',
            'last_name_submitted' => 'Person',
            'household_no_submitted' => 'HH-DENIED',
            'email_submitted' => 'denied.hr@example.com',
            'decision_reason' => 'The submitted household number could not be verified.',
        ]);

        $html = $this->asAdmin()
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-hr-status="Pending"', $html);
        $this->assertStringContainsString('data-hr-status="Denied"', $html);
        $this->assertSame(RecordRequest::STATUS_PENDING, RecordRequest::query()->where('email_submitted', 'pending.hr@example.com')->value('status'));
        $this->assertSame(RecordRequest::STATUS_DENIED, RecordRequest::query()->where('email_submitted', 'denied.hr@example.com')->value('status'));
    }

    public function test_viewing_the_list_does_not_write_record_requests(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $row = $this->seedRequest($account, RecordRequest::STATUS_PENDING);
        $updatedAt = $row->updated_at?->toJSON();
        $count = RecordRequest::query()->count();

        $this->asAdmin()->get(route('household-requests.index'))->assertOk();

        $this->assertSame($count, RecordRequest::query()->count());
        $this->assertSame($updatedAt, $row->fresh()->updated_at?->toJSON());
        $this->assertSame(RecordRequest::STATUS_PENDING, $row->fresh()->status);
    }

    public function test_non_admin_cannot_open_household_requests(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('household-requests.index'))
            ->assertForbidden();
    }

    public function test_view_shows_read_only_snapshot_without_approve_controls(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $row = $this->seedRequest($account, RecordRequest::STATUS_DENIED, [
            'decision_reason' => 'The submitted resident information does not match the household record.',
            'evaluated_at' => now(),
        ]);
        $id = HouseholdRecordRequestUiCatalog::publicId((int) $row->request_id);

        $html = $this->asAdmin()
            ->get(route('household-requests.view', ['id' => $id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Ana Cruz Santos', $html);
        $this->assertStringContainsString('HH-151', $html);
        $this->assertStringContainsString('The submitted resident information does not match the household record.', $html);
        $this->assertStringNotContainsString('Approve request', $html);
        $this->assertStringNotContainsString('>Verify</', $html);
        $this->assertStringNotContainsString('198.51.100.20', $html);
        $this->assertSame($row->updated_at?->toJSON(), $row->fresh()->updated_at?->toJSON());
    }

    public function test_view_shows_account_identity_denial_reason_with_requester_account(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'first_name' => 'Kathlyn',
            'middle_name' => 'Periabras',
            'last_name' => 'Ibo',
            'email' => 'kaibo@my.cspc.edu.ph',
        ]));
        $reason = \App\Support\HouseholdRecordRequestMatcher::accountIdentityDenialReason($account, true, false);
        $row = $this->seedRequest($account, RecordRequest::STATUS_DENIED, [
            'first_name_submitted' => 'Kathlyn',
            'middle_name_submitted' => 'Periabras',
            'last_name_submitted' => 'Ibo',
            'email_submitted' => 'fake@gmail.com',
            'decision_reason' => $reason,
            'evaluated_at' => now(),
        ]);
        $id = HouseholdRecordRequestUiCatalog::publicId((int) $row->request_id);

        $html = $this->asAdmin()
            ->get(route('household-requests.view', ['id' => $id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('requester&#039;s registered chatbot account email', $html);
        $this->assertStringContainsString('Kathlyn Periabras Ibo (kaibo@my.cspc.edu.ph)', $html);
        $this->assertStringContainsString('fake@gmail.com', $html); // submitted snapshot email field
        $this->assertStringNotContainsString('Approve request', $html);
    }
}
