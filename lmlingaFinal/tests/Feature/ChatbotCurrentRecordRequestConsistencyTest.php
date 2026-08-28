<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestOtpIssuer;
use App\Support\HouseholdRecordRequestUiCatalog;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChatbotCurrentRecordRequestConsistencyTest extends TestCase
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
            'email' => 'ana.current.rr@example.com',
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
     * @param  array<string, mixed>  $overrides
     */
    private function seedRequest(ResidentAccount $account, string $status, array $overrides = []): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = $overrides['household_no_submitted'] ?? '151';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = $account->first_name;
        $row->middle_name_submitted = $account->middle_name;
        $row->last_name_submitted = $account->last_name;
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '198.51.100.44';
        $row->matched_resident_id = $overrides['matched_resident_id'] ?? null;
        $row->status = $status;
        $row->decision_reason = $overrides['decision_reason'] ?? null;
        $row->evaluated_at = $overrides['evaluated_at'] ?? now();
        $row->approved_at = $overrides['approved_at'] ?? (
            $status === RecordRequest::STATUS_APPROVED ? now() : null
        );
        $row->save();

        return $row->fresh();
    }

    private function seedVerifiedEmailOtp(RecordRequest $request, ResidentAccount $account): RecordRequestOtp
    {
        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = Hash::make('123456');
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_EMAIL, (string) $account->email);
        $otp->expires_at = now()->addMinutes(5);
        $otp->attempt_count = 1;
        $otp->resend_count = 0;
        $otp->last_sent_at = now()->subMinute();
        $otp->verified_at = now();
        $otp->invalidated_at = null;
        $otp->save();

        return $otp->fresh();
    }

    /**
     * @return array{0: ResidentAccount, 1: mixed, 2: RecordRequest, 3: RecordRequest}
     */
    private function accountWithHistoricalApprovedAndCurrent(string $currentStatus): array
    {
        $household = Household::factory()->create(['household_no' => '151']);
        $head = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'relation' => 'Head',
        ]);
        $residentKey = $this->chatbotRelationshipKey($head);

        $account = $this->actingAsResidentAccount([
            'resident_id' => $residentKey,
        ]);

        $historical = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => $residentKey,
            'decision_reason' => 'Historical approved request.',
        ]);
        $this->seedVerifiedEmailOtp($historical, $account);

        $current = $this->seedRequest($account, $currentStatus, [
            'matched_resident_id' => $currentStatus === RecordRequest::STATUS_DENIED
                ? null
                : $residentKey,
            'approved_at' => $currentStatus === RecordRequest::STATUS_APPROVED ? now() : null,
            'decision_reason' => $currentStatus === RecordRequest::STATUS_DENIED
                ? 'The submitted household number could not be verified.'
                : null,
        ]);

        return [$account, $residentKey, $historical, $current];
    }

    private function assertSidebarHouseholdVisible(string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH 151\s*<\/span>/s',
            $html
        );
    }

    public function test_latest_for_account_is_highest_request_id(): void
    {
        [$account, , $historical, $current] = $this->accountWithHistoricalApprovedAndCurrent(
            RecordRequest::STATUS_DENIED
        );

        $latest = RecordRequest::latestForAccount($account->account_id);

        $this->assertNotNull($latest);
        $this->assertSame((int) $current->request_id, (int) $latest->request_id);
        $this->assertTrue($current->fresh()->isCurrentForAccount());
        $this->assertFalse($historical->fresh()->isCurrentForAccount());
        $this->assertGreaterThan((int) $historical->request_id, (int) $current->request_id);
    }

    public function test_current_denied_overrides_historical_approved_with_otp_and_resident_id(): void
    {
        [$account, $residentKey, $historical, $current] = $this->accountWithHistoricalApprovedAndCurrent(
            RecordRequest::STATUS_DENIED
        );

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertSame(RecordRequest::STATUS_DENIED, RecordRequest::latestForAccount($account->account_id)?->status);
        $this->assertSame(RecordRequest::STATUS_APPROVED, $historical->fresh()->status);
        $this->assertNotNull(
            RecordRequestOtp::query()->where('request_id', $historical->request_id)->whereNotNull('verified_at')->first()
        );
        $this->assertSame($residentKey, $account->fresh()->resident_id);

        $this->assertStringContainsString('Request Could Not Be Verified', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Continue Verification', $html);
        $this->assertStringContainsString('data-lml-verification-state="unverified"', $html);
        $this->assertSidebarHouseholdVisible($html);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_DENIED, $current->fresh()->status);
        $this->assertSame(RecordRequest::STATUS_APPROVED, $historical->fresh()->status);
        $this->assertSame($residentKey, $account->fresh()->resident_id);
    }

    public function test_current_pending_overrides_historical_approved(): void
    {
        [$account, $residentKey, $historical] = $this->accountWithHistoricalApprovedAndCurrent(
            RecordRequest::STATUS_PENDING
        );

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSidebarHouseholdVisible($html);
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $historical->fresh()->status);
        $this->assertSame($residentKey, $account->fresh()->resident_id);
        $this->assertSame(RecordRequest::STATUS_PENDING, RecordRequest::latestForAccount($account->account_id)?->status);
    }

    public function test_current_awaiting_otp_overrides_historical_approved(): void
    {
        [$account, $residentKey, $historical] = $this->accountWithHistoricalApprovedAndCurrent(
            RecordRequest::STATUS_AWAITING_OTP
        );

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Continue Verification', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSidebarHouseholdVisible($html);
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, $historical->fresh()->status);
        $this->assertSame($residentKey, $account->fresh()->resident_id);
    }

    public function test_current_approved_without_otp_is_not_verified(): void
    {
        [$account, $residentKey, $historical, $current] = $this->accountWithHistoricalApprovedAndCurrent(
            RecordRequest::STATUS_APPROVED
        );

        $this->assertSame(
            0,
            RecordRequestOtp::query()->where('request_id', $current->request_id)->whereNotNull('verified_at')->count()
        );
        $this->assertGreaterThan(
            0,
            RecordRequestOtp::query()->where('request_id', $historical->request_id)->whereNotNull('verified_at')->count()
        );

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Continue Verification', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertSidebarHouseholdVisible($html);
        $this->get(route('chatbot.household.information'))->assertRedirect(route('chatbot.main'));
        $this->assertSame($residentKey, $account->fresh()->resident_id);
        $this->assertSame(RecordRequest::STATUS_APPROVED, $historical->fresh()->status);
    }

    public function test_current_approved_with_otp_grants_access_and_shows_household_number(): void
    {
        [$account, $residentKey, $historical, $current] = $this->accountWithHistoricalApprovedAndCurrent(
            RecordRequest::STATUS_APPROVED
        );
        $this->seedVerifiedEmailOtp($current, $account);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Access Household Record', $html);
        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH 151\s*<\/span>/s',
            $html
        );
        $this->get(route('chatbot.household.information'))->assertOk();
        $this->assertSame($residentKey, $account->fresh()->resident_id);
        $this->assertSame(RecordRequest::STATUS_APPROVED, $historical->fresh()->status);
        $this->assertTrue($current->fresh()->isCurrentForAccount());
    }

    public function test_admin_marks_current_and_historical_for_same_account(): void
    {
        [$account, , $historical, $current] = $this->accountWithHistoricalApprovedAndCurrent(
            RecordRequest::STATUS_DENIED
        );

        $list = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.index'))
            ->assertOk()
            ->getContent();

        $currentPublicId = HouseholdRecordRequestUiCatalog::publicId((int) $current->request_id);
        $historicalPublicId = HouseholdRecordRequestUiCatalog::publicId((int) $historical->request_id);

        $this->assertMatchesRegularExpression(
            '/data-hr-id="'.preg_quote($currentPublicId, '/').'"[^>]*data-hr-current="1"/s',
            $list
        );
        $this->assertMatchesRegularExpression(
            '/data-hr-id="'.preg_quote($historicalPublicId, '/').'"[^>]*data-hr-current="0"/s',
            $list
        );
        $this->assertMatchesRegularExpression('/lml-hr-table__scope--current[\s\S]*?>\s*Current\s*<\/span>/', $list);
        $this->assertMatchesRegularExpression('/lml-hr-table__scope--historical[\s\S]*?>\s*Historical\s*<\/span>/', $list);

        $currentView = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.view', ['id' => $currentPublicId]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-hr-current="1"', $currentView);
        $this->assertMatchesRegularExpression('/lml-hr-table__scope--current[\s\S]*?>\s*Current\s*<\/span>/', $currentView);
        $this->assertStringContainsString('Denied', $currentView);

        $historicalView = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('household-requests.view', ['id' => $historicalPublicId]))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('data-hr-current="0"', $historicalView);
        $this->assertMatchesRegularExpression('/lml-hr-table__scope--historical[\s\S]*?>\s*Historical\s*<\/span>/', $historicalView);
        $this->assertStringContainsString('Approved', $historicalView);

        $this->assertSame(RecordRequest::STATUS_APPROVED, $historical->fresh()->status);
        $this->assertSame(RecordRequest::STATUS_DENIED, $current->fresh()->status);
        $this->assertSame($account->account_id, $historical->fresh()->account_id);
        $this->assertSame($account->account_id, $current->fresh()->account_id);
    }
}
