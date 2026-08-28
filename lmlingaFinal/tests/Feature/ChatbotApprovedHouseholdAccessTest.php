<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestOtpIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ChatbotApprovedHouseholdAccessTest extends TestCase
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
            'email' => 'ana.approved.hh@example.com',
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
        $row->submitter_ip = '198.51.100.10';
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

    /**
     * @return array{
     *     0: ResidentAccount,
     *     1: Household,
     *     2: Resident,
     *     3: Resident,
     *     4: RecordRequest
     * }
     */
    private function approvedLinkedHousehold(): array
    {
        $household = Household::factory()->create(['household_no' => '151']);

        $head = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-151-01',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'relation' => 'Head',
            'birthday' => now()->subYears(38)->toDateString(),
            'sex' => 'Female',
            'relationship_status' => 'Married',
            'occupation' => 'Teacher',
        ]);

        $child = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-151-02',
            'first_name' => 'Jaica',
            'middle_name' => 'A',
            'last_name' => 'Santos',
            'relation' => 'Daughter',
            'birthday' => now()->subMonths(8)->toDateString(),
            'sex' => 'Female',
            'relationship_status' => 'Single',
            'occupation' => 'None / N/A',
        ]);

        $otherHousehold = Household::factory()->create(['household_no' => '999']);
        Resident::factory()->create([
            'household_id' => $otherHousehold->getKey(),
            'member_no' => 'MB-999-01',
            'first_name' => 'Foreign',
            'middle_name' => 'X',
            'last_name' => 'Household',
            'relation' => 'Head',
            'birthday' => now()->subYears(40)->toDateString(),
            'sex' => 'Male',
        ]);

        $residentKey = $this->chatbotRelationshipKey($head);
        $account = $this->actingAsResidentAccount([
            'resident_id' => $residentKey,
        ]);

        $request = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => $residentKey,
            'household_no_submitted' => '151',
            'decision_reason' => \App\Support\HouseholdRecordRequestMatcher::REASON_AWAITING_OTP,
        ]);
        $this->seedVerifiedEmailOtp($request, $account);

        return [$account, $household, $head, $child, $request];
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
     * @return array{0: ResidentAccount, 1: RecordRequest, 2: Resident}
     */
    private function legacyApprovedWithoutOtp(): array
    {
        $household = Household::factory()->create(['household_no' => '151']);
        $head = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-LEG-01',
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'relation' => 'Head',
        ]);
        $residentKey = $this->chatbotRelationshipKey($head);
        $account = $this->actingAsResidentAccount(['resident_id' => $residentKey]);
        $request = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => $residentKey,
        ]);

        return [$account, $request, $head];
    }

    public function test_approved_cta_points_to_authorized_household_information_not_sms(): void
    {
        [$account] = $this->approvedLinkedHousehold();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Access Household Record', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.information')).'"', $html);
        $this->assertStringContainsString('bi-patch-check-fill', $html);
        $this->assertStringNotContainsString('Complete OTP verification to continue.', $html);
        $this->assertStringNotContainsString('lml-chatbot-main__household-reason', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $html);
        $this->assertSame(RecordRequest::STATUS_APPROVED, RecordRequest::latestForAccount($account->account_id)?->status);
    }

    public function test_legacy_approved_without_otp_does_not_show_access_household_record(): void
    {
        $this->legacyApprovedWithoutOtp();

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();

        $this->assertStringContainsString('Continue Verification', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);
    }

    public function test_legacy_approved_without_otp_cannot_open_household_information(): void
    {
        $this->legacyApprovedWithoutOtp();

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_legacy_approved_without_otp_can_open_sms_ui(): void
    {
        $this->legacyApprovedWithoutOtp();

        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.household.verification.sms'));

        $this->get(route('chatbot.household.verification.sms'))
            ->assertOk()
            ->assertSee('SMS Verification', false);
    }

    public function test_approved_authenticated_resident_can_open_authorized_household_information(): void
    {
        [, $household, $head, $child] = $this->approvedLinkedHousehold();

        $html = $this->get(route('chatbot.household.information'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Household Member Information', $html);
        $this->assertStringContainsString('Ana Cruz Santos', $html);
        $this->assertStringContainsString('HH 151', $html);
        $this->assertStringContainsString((string) $household->household_no, $html);
        $this->assertStringContainsString($head->first_name.' '.$head->middle_name.' '.$head->last_name, $html);
        $this->assertStringContainsString($child->first_name.' '.$child->middle_name.' '.$child->last_name, $html);
        $this->assertStringContainsString('Head of Household', $html);
        $this->assertStringContainsString('Daughter', $html);
        $this->assertStringNotContainsString('Foreign X Household', $html);
        $this->assertStringNotContainsString('Jane A. Doe', $html);
        $this->assertStringNotContainsString('HH 123', $html);
    }

    public function test_query_tampering_cannot_select_another_household(): void
    {
        $this->approvedLinkedHousehold();

        $html = $this->get(route('chatbot.household.information', [
            'household_no' => '999',
            'householdNo' => '999',
            'resident_id' => '999',
            'matched_resident_id' => '999',
            'account_id' => '999',
            'request_id' => '999',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('HH 151', $html);
        $this->assertStringContainsString('Ana Cruz Santos', $html);
        $this->assertStringContainsString('Jaica A Santos', $html);
        $this->assertStringNotContainsString('Foreign X Household', $html);
        $this->assertStringNotContainsString('HH 999', $html);
    }

    public function test_pending_cannot_access_household_information(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_PENDING);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_awaiting_otp_cannot_access_household_information(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_AWAITING_OTP, [
            'matched_resident_id' => 42,
            'approved_at' => null,
        ]);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_denied_cannot_access_household_information(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->seedRequest($account, RecordRequest::STATUS_DENIED, [
            'matched_resident_id' => null,
            'approved_at' => null,
        ]);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_account_without_approved_request_cannot_access(): void
    {
        $this->actingAsResidentAccount();

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_guest_is_redirected_to_chatbot_login(): void
    {
        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_approved_with_null_resident_id_fails_closed(): void
    {
        $account = $this->actingAsResidentAccount(['resident_id' => null]);
        $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => 42,
        ]);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_missing_resident_fails_closed(): void
    {
        $household = Household::factory()->create(['household_no' => '410']);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Gone',
            'last_name' => 'Resident',
        ]);
        $residentKey = $this->chatbotRelationshipKey($resident);

        $account = $this->actingAsResidentAccount(['resident_id' => $residentKey]);
        $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => $residentKey,
        ]);

        $resident->delete();

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_missing_household_fails_closed(): void
    {
        $household = Household::factory()->create(['household_no' => '404']);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Orphan',
            'last_name' => 'Link',
        ]);
        $residentKey = $this->chatbotRelationshipKey($resident);
        $household->delete();

        $account = $this->actingAsResidentAccount(['resident_id' => $residentKey]);
        $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => $residentKey,
        ]);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_foreign_account_cannot_access_another_accounts_household(): void
    {
        [$owner] = $this->approvedLinkedHousehold();

        $viewer = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'viewer.hh@example.com',
            'resident_id' => null,
        ]));
        $this->withSession(['resident_account_id' => $viewer->account_id]);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_APPROVED, RecordRequest::latestForAccount($owner->account_id)?->status);
        $this->assertSame(0, RecordRequest::query()->where('account_id', $viewer->account_id)->count());
    }

    public function test_matched_resident_inconsistent_with_linked_resident_fails_closed(): void
    {
        $household = Household::factory()->create(['household_no' => '151']);
        $linked = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Linked',
            'last_name' => 'Resident',
        ]);
        $other = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-151-99',
            'first_name' => 'Other',
            'last_name' => 'Match',
        ]);

        $linkedKey = $this->chatbotRelationshipKey($linked);
        $otherKey = $this->chatbotRelationshipKey($other);

        $account = $this->actingAsResidentAccount(['resident_id' => $linkedKey]);
        $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => $otherKey,
        ]);

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_sms_otp_route_still_rejects_approved(): void
    {
        Mail::fake();
        Notification::fake();

        $this->approvedLinkedHousehold();

        $this->get(route('chatbot.household.verification.sms'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_email_otp_route_still_rejects_approved(): void
    {
        Mail::fake();
        Notification::fake();

        $this->approvedLinkedHousehold();

        $this->get(route('chatbot.household.verification.email'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_verified_main_and_household_share_hh_display_and_access_route(): void
    {
        $this->approvedLinkedHousehold();

        $main = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $household = $this->get(route('chatbot.household.information'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH 151\s*<\/span>/s',
            $main
        );
        $this->assertStringContainsString('HH 151', $household);
        $this->assertStringContainsString('Access Household Record', $main);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.information')).'"', $main);
        $this->assertStringContainsString('data-lml-verification-state="verified"', $main);

        // Back to Main after household still shows Access (not OTP / Request).
        $backToMain = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Access Household Record', $backToMain);
        $this->assertStringNotContainsString('Continue Verification', $backToMain);
        $this->assertStringNotContainsString('Request Household Record', $backToMain);
        $this->assertStringNotContainsString('Request Sent', $backToMain);
        $this->assertStringNotContainsString('Request Could Not Be Verified', $backToMain);

        // Access Household Record opens household — never OTP routes.
        $this->get(route('chatbot.household.information'))->assertOk();
        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.verification.email'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_verification_query_cannot_fake_verified_main_or_household_access(): void
    {
        $this->actingAsResidentAccount(['email' => 'unverified.query@example.com']);

        $main = $this->get(route('chatbot.main', [
            'verification' => 'verified',
            'status' => 'Approved',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Request Household Record', $main);
        $this->assertStringNotContainsString('Access Household Record', $main);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $main);
        $this->assertStringContainsString('data-lml-verification-state="unverified"', $main);
        $this->assertDoesNotMatchRegularExpression(
            '/class="lml-chatbot-main__household"[^>]*>.*?<span>\s*HH 151\s*<\/span>/s',
            $main
        );

        $this->get(route('chatbot.household.information', ['verification' => 'verified']))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_household_members_ordered_by_family_structure_without_member_no(): void
    {
        $household = Household::factory()->create(['household_no' => '151']);
        $otherHousehold = Household::factory()->create(['household_no' => '999']);

        // Intentionally reverse member_no vs family order so member_no cannot be the sort key.
        $youngDaughter = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-AAA-01',
            'first_name' => 'Young',
            'middle_name' => 'A',
            'last_name' => 'Child',
            'relation' => 'Daughter',
            'birthday' => now()->subYears(5)->toDateString(),
            'sex' => 'Female',
        ]);
        $oldSon = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-AAA-02',
            'first_name' => 'Older',
            'middle_name' => 'B',
            'last_name' => 'Son',
            'relation' => 'Son',
            'birthday' => now()->subYears(22)->toDateString(),
            'sex' => 'Male',
        ]);
        $spouse = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-AAA-03',
            'first_name' => 'Spouse',
            'middle_name' => 'C',
            'last_name' => 'Partner',
            'relation' => 'Spouse',
            'birthday' => now()->subYears(43)->toDateString(),
            'sex' => 'Female',
        ]);
        $parent = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-AAA-04',
            'first_name' => 'Parent',
            'middle_name' => 'D',
            'last_name' => 'Elder',
            'relation' => 'Parent',
            'birthday' => now()->subYears(70)->toDateString(),
            'sex' => 'Male',
        ]);
        $head = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'member_no' => 'MB-ZZZ-99',
            'first_name' => 'Head',
            'middle_name' => 'E',
            'last_name' => 'Leader',
            'relation' => 'Head',
            'birthday' => now()->subYears(46)->toDateString(),
            'sex' => 'Male',
        ]);
        Resident::factory()->create([
            'household_id' => $otherHousehold->getKey(),
            'member_no' => 'MB-OUT-01',
            'first_name' => 'Foreign',
            'middle_name' => 'X',
            'last_name' => 'Outsider',
            'relation' => 'Head',
            'birthday' => now()->subYears(50)->toDateString(),
            'sex' => 'Male',
        ]);

        $residentKey = $this->chatbotRelationshipKey($head);
        $account = $this->actingAsResidentAccount([
            'email' => 'order.family@example.com',
            'first_name' => 'Head',
            'middle_name' => 'E',
            'last_name' => 'Leader',
            'resident_id' => $residentKey,
        ]);
        $request = $this->seedRequest($account, RecordRequest::STATUS_APPROVED, [
            'matched_resident_id' => $residentKey,
            'household_no_submitted' => '151',
        ]);
        $this->seedVerifiedEmailOtp($request, $account);

        $html = $this->get(route('chatbot.household.information'))->assertOk()->getContent();

        $this->assertStringContainsString('Total<br>Members', $html);
        $this->assertMatchesRegularExpression(
            '/aria-label="5 total household members".*?<strong>\s*5\s*<\/strong>/s',
            $html
        );
        $this->assertStringNotContainsString('Foreign X Outsider', $html);
        $this->assertStringNotContainsString('member_no', $html);

        $positions = [
            'Head E Leader' => strpos($html, 'Head E Leader'),
            'Spouse C Partner' => strpos($html, 'Spouse C Partner'),
            'Parent D Elder' => strpos($html, 'Parent D Elder'),
            'Older B Son' => strpos($html, 'Older B Son'),
            'Young A Child' => strpos($html, 'Young A Child'),
        ];

        foreach ($positions as $label => $pos) {
            $this->assertNotFalse($pos, $label.' missing from household page');
        }

        $this->assertTrue($positions['Head E Leader'] < $positions['Spouse C Partner']);
        $this->assertTrue($positions['Spouse C Partner'] < $positions['Parent D Elder']);
        $this->assertTrue($positions['Parent D Elder'] < $positions['Older B Son']);
        $this->assertTrue($positions['Older B Son'] < $positions['Young A Child']);

        // member_no lexicographic order would put young daughter before head (MB-AAA vs MB-ZZZ).
        $this->assertTrue($positions['Head E Leader'] < $positions['Young A Child']);
    }
}
