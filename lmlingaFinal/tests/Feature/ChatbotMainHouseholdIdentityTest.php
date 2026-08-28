<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\Resident;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatbotMainHouseholdIdentityTest extends TestCase
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
            'email' => 'juan.hh@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    private function chatbotRelationshipKey(Resident $resident): mixed
    {
        return $resident->getAttribute(Resident::resolvedPrimaryKeyName());
    }

    /**
     * @return array{0: ResidentAccount, 1: Household, 2: Resident}
     */
    private function linkedAccount(string $householdNo, array $accountOverrides = []): array
    {
        $household = Household::factory()->create(['household_no' => $householdNo]);
        $resident = Resident::factory()->create(['household_id' => $household->getKey()]);
        $account = $this->actingAsResidentAccount(array_merge([
            'resident_id' => $this->chatbotRelationshipKey($resident),
        ], $accountOverrides));

        return [$account, $household, $resident];
    }

    private function assertHouseholdDisplay(string $html, string $value): void
    {
        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*'.preg_quote($value, '/').'\s*<\/span>/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH 123\s*<\/span>/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*(N\/A|Unknown|Household Not Linked)\s*<\/span>/s',
            $html
        );
    }

    private function seedPendingRequest(ResidentAccount $account, string $submittedHouseholdNo): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = $submittedHouseholdNo;
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

        return $row->fresh();
    }

    public function test_linked_account_shows_official_household_no(): void
    {
        [$account] = $this->linkedAccount('HH-001');
        $updatedAt = $account->updated_at?->toJSON();
        $residentId = $account->resident_id;

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, 'HH-001');
        $this->assertStringContainsString('Juan Santos Dela Cruz', $html);
        $this->assertStringContainsString('Hi, Juan Santos Dela Cruz!', $html);
        $this->assertStringContainsString('Request Household Record', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSame($updatedAt, $account->fresh()->updated_at?->toJSON());
        $this->assertSame($residentId, $account->fresh()->resident_id);
        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_another_account_household_number_is_not_shown(): void
    {
        $otherHousehold = Household::factory()->create(['household_no' => 'HH-999']);
        $otherResident = Resident::factory()->create(['household_id' => $otherHousehold->getKey()]);
        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'other.hh@example.com',
            'resident_id' => $this->chatbotRelationshipKey($otherResident),
        ]));

        $this->linkedAccount('HH-001', ['email' => 'own.hh@example.com']);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, 'HH-001');
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH-999\s*<\/span>/s',
            $html
        );
    }

    public function test_null_resident_id_shows_dash(): void
    {
        $this->actingAsResidentAccount(['resident_id' => null]);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, '-');
        $this->assertStringContainsString('Request Household Record', $html);
    }

    public function test_missing_resident_shows_dash(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);
        $resident = Resident::factory()->create(['household_id' => $household->getKey()]);
        $key = $this->chatbotRelationshipKey($resident);
        $this->actingAsResidentAccount(['resident_id' => $key]);

        Schema::disableForeignKeyConstraints();
        DB::table('residents')->where(Resident::resolvedPrimaryKeyName(), $key)->delete();
        Schema::enableForeignKeyConstraints();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, '-');
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH-001\s*<\/span>/s',
            $html
        );
    }

    public function test_missing_household_shows_dash(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);
        $resident = Resident::factory()->create(['household_id' => $household->getKey()]);
        $this->actingAsResidentAccount(['resident_id' => $this->chatbotRelationshipKey($resident)]);

        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('households')->where($household->getKeyName(), $household->getKey())->delete();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, '-');
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH-001\s*<\/span>/s',
            $html
        );
    }

    public function test_guest_and_stale_session_show_dash(): void
    {
        $guestHtml = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertHouseholdDisplay($guestHtml, '-');
        $this->assertStringContainsString('Hi, Resident!', $guestHtml);

        $staleHtml = $this->withSession(['resident_account_id' => 999999])
            ->get(route('chatbot.main'))
            ->assertOk()
            ->getContent();
        $this->assertHouseholdDisplay($staleHtml, '-');
        $this->assertStringContainsString('Hi, Resident!', $staleHtml);
    }

    public function test_browser_identity_values_cannot_select_another_household(): void
    {
        $otherHousehold = Household::factory()->create(['household_no' => 'HH-777']);
        $otherResident = Resident::factory()->create(['household_id' => $otherHousehold->getKey()]);
        $otherAccount = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'browser.other@example.com',
            'resident_id' => $this->chatbotRelationshipKey($otherResident),
        ]));

        [$account] = $this->linkedAccount('HH-001', ['email' => 'browser.own@example.com']);

        $html = $this->get(route('chatbot.main', [
            'account_id' => $otherAccount->account_id,
            'resident_id' => $otherAccount->resident_id,
            'household_id' => $otherHousehold->getKey(),
            'household_no' => 'HH-777',
            'name' => 'Hacker Name',
        ]))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, 'HH-001');
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH-777\s*<\/span>/s',
            $html
        );
        $this->assertStringContainsString('Juan Santos Dela Cruz', $html);
        $this->assertStringNotContainsString('Hacker Name', $html);
        $this->assertSame($account->resident_id, $account->fresh()->resident_id);
    }

    public function test_submitted_record_request_household_no_is_not_used_as_official_display(): void
    {
        [$account] = $this->linkedAccount('HH-001');
        $row = $this->seedPendingRequest($account, 'HH-151');
        $updatedAt = $row->updated_at?->toJSON();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, 'HH-001');
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH-151\s*<\/span>/s',
            $html
        );
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString(route('chatbot.household.verification.status'), $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSame('Pending', $row->fresh()->status);
        $this->assertSame('HH-151', $row->fresh()->household_no_submitted);
        $this->assertSame($updatedAt, $row->fresh()->updated_at?->toJSON());
        $this->assertNull($row->fresh()->matched_resident_id);
    }

    public function test_unlinked_pending_request_does_not_display_submitted_household_no(): void
    {
        $account = $this->actingAsResidentAccount(['resident_id' => null]);
        $this->seedPendingRequest($account, 'HH-151');

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertHouseholdDisplay($html, '-');
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH-151\s*<\/span>/s',
            $html
        );
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Verification Pending', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
    }
}
