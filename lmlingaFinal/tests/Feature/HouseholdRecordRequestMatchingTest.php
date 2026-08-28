<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Support\HouseholdRecordRequestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HouseholdRecordRequestMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.iprog.base_url', 'https://www.iprogsms.com/api/v1');
        Config::set('services.iprog.api_token', 'test-iprog-token');
        Http::preventStrayRequests();
        Http::fake([
            'https://www.iprogsms.com/api/v1/sms_messages' => Http::response([
                'status' => 200,
                'message' => 'Your SMS message has been successfully added to the queue and will be processed shortly.',
                'message_id' => 'iSms-MatchTest',
            ], 200),
        ]);
    }

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
            'email' => 'ana.match@example.com',
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
            'householdNo' => 'HH-001',
            'relationship' => 'Household Head',
            'firstName' => 'Ana',
            'middleName' => 'Cruz',
            'lastName' => 'Santos',
            'mobileNumber' => '09171234567',
            'emailAddress' => 'ana.match@example.com',
        ], $overrides);
    }

    private function actingAsResidentAccount(array $overrides = []): ResidentAccount
    {
        $account = ResidentAccount::query()->create($this->accountAttributes($overrides));

        $this->withSession(['resident_account_id' => $account->account_id]);

        return $account->fresh();
    }

    /**
     * @return array{0: Household, 1: Resident}
     */
    private function officialHouseholdResident(string $householdNo, array $residentOverrides = []): array
    {
        $household = Household::factory()->create(['household_no' => $householdNo]);
        $resident = Resident::factory()->create(array_merge([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ], $residentOverrides));

        return [$household, $resident];
    }

    public function test_account_name_mismatch_denies_request(): void
    {
        $this->officialHouseholdResident('HH-001');
        $account = $this->actingAsResidentAccount([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $expectedReason = HouseholdRecordRequestMatcher::accountIdentityDenialReason($account, false, true);

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame($expectedReason, $row->decision_reason);
        $this->assertStringContainsString('Juan Santos Dela Cruz (ana.match@example.com)', $row->decision_reason);
        $this->assertStringNotContainsString('Ana Cruz Santos', $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNotNull($row->evaluated_at);
        $this->assertNull($row->approved_at);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertStringNotContainsString('resident_id', (string) $row->decision_reason);
        $this->assertStringNotContainsString('account_id', (string) $row->decision_reason);
        $this->assertStringNotContainsString('request_id', (string) $row->decision_reason);

        $this->get(route('chatbot.household.verification.sms'))
            ->assertRedirect(route('chatbot.main'));

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Request Could Not Be Verified', $html);
        $this->assertStringContainsString('Juan Santos Dela Cruz (ana.match@example.com)', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('account_id', $html);
        $this->assertStringNotContainsString('matched_resident_id', $html);
        $this->assertStringNotContainsString('household_id', $html);
        $this->assertStringNotContainsString('request_id', $html);
    }

    public function test_household_number_not_found_denies_request(): void
    {
        $this->officialHouseholdResident('HH-001');
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'householdNo' => 'HH-999',
        ]))->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNotNull($row->evaluated_at);
        $this->assertNull($account->fresh()->resident_id);
    }

    public function test_household_found_but_resident_name_mismatch_denies_request(): void
    {
        $this->officialHouseholdResident('HH-001', [
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNotNull($row->evaluated_at);
        $this->assertNull($row->approved_at);
    }

    public function test_official_null_middle_name_does_not_match_submitted_middle_name(): void
    {
        $this->officialHouseholdResident('HH-001', ['middle_name' => null]);
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
    }

    public function test_exact_unique_match_sets_awaiting_otp_sends_sms_and_opens_sms_ui(): void
    {
        Mail::fake();

        [$household, $resident] = $this->officialHouseholdResident('HH-001');
        $account = $this->actingAsResidentAccount(['resident_id' => null]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'relationship' => 'Spouse',
            'mobileNumber' => '09170000000',
            'emailAddress' => 'ana.match@example.com',
            'matched_resident_id' => '999999',
            'status' => 'Approved',
            'account_id' => '888',
            'resident_id' => '777',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertNotNull($row);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->status);
        $this->assertNotSame(RecordRequest::STATUS_APPROVED, $row->status);
        $this->assertSame((int) $resident->getKey(), (int) $row->matched_resident_id);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_AWAITING_OTP, $row->decision_reason);
        $this->assertNotNull($row->evaluated_at);
        $this->assertNull($row->approved_at);
        $this->assertSame((int) $account->account_id, (int) $row->account_id);
        $this->assertSame('Spouse', $row->relationship_submitted);
        $this->assertSame('09170000000', $row->mobile_number_submitted);
        $this->assertSame('ana.match@example.com', $row->email_submitted);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertSame($household->getKey(), $resident->fresh()->household_id);
        $this->assertDatabaseCount('record_request_otps', 1);
        Http::assertSentCount(1);

        Mail::assertNothingOutgoing();

        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.household.verification.sms'));
        Http::assertSentCount(1);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Continue Verification', $html);
        $this->assertStringContainsString('href="'.e(route('chatbot.household.verification.sms')).'"', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
        $this->assertStringNotContainsString('href="'.e(route('chatbot.household.information')).'"', $html);
    }

    public function test_email_mismatch_denies_even_when_personal_info_matches(): void
    {
        Mail::fake();
        $this->officialHouseholdResident('HH-001', [
            'first_name' => 'Kathlyn',
            'middle_name' => 'Periabras',
            'last_name' => 'Ibo',
        ]);
        $account = $this->actingAsResidentAccount([
            'first_name' => 'Kathlyn',
            'middle_name' => 'Periabras',
            'last_name' => 'Ibo',
            'email' => 'kaibo@my.cspc.edu.ph',
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'firstName' => 'Kathlyn',
            'middleName' => 'Periabras',
            'lastName' => 'Ibo',
            'emailAddress' => 'other.contact@example.com',
        ]))->assertRedirect(route('chatbot.main'));

        $expectedReason = HouseholdRecordRequestMatcher::accountIdentityDenialReason($account, true, false);

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame($expectedReason, $row->decision_reason);
        $this->assertStringContainsString(
            'The submitted email address does not match the requester\'s registered chatbot account email.',
            $row->decision_reason
        );
        $this->assertStringContainsString('Kathlyn Periabras Ibo (kaibo@my.cspc.edu.ph)', $row->decision_reason);
        $this->assertStringNotContainsString('other.contact@example.com', $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNull($row->approved_at);
        $this->assertNotNull($row->evaluated_at);
        $this->assertSame('other.contact@example.com', $row->email_submitted);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertDatabaseCount('record_request_otps', 0);
        Mail::assertNothingOutgoing();
        Http::assertNothingSent();

        $this->get(route('chatbot.household.verification.otp-method'))
            ->assertRedirect(route('chatbot.main'));
        $this->get(route('chatbot.household.verification.email'))
            ->assertRedirect(route('chatbot.main'));
    }

    public function test_email_match_is_case_and_trim_insensitive(): void
    {
        Mail::fake();
        [, $resident] = $this->officialHouseholdResident('HH-001');
        $this->actingAsResidentAccount(['email' => 'Ana.Match@Example.com']);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => '  ana.match@example.com  ',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->status);
        $this->assertSame((int) $resident->getKey(), (int) $row->matched_resident_id);
        $this->assertNull($row->approved_at);
        $this->assertDatabaseCount('record_request_otps', 1);
        Mail::assertNothingOutgoing();
        Http::assertSentCount(1);
    }

    public function test_email_matches_but_personal_info_mismatch_denies(): void
    {
        Mail::fake();
        $this->officialHouseholdResident('HH-001', [
            'first_name' => 'Maria',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'ana.match@example.com',
        ]))->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNull($row->approved_at);
        $this->assertDatabaseCount('record_request_otps', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_email_and_name_both_mismatch_denies_with_combined_account_reason(): void
    {
        Mail::fake();
        $this->officialHouseholdResident('HH-001');
        $account = $this->actingAsResidentAccount([
            'first_name' => 'Kathlyn',
            'middle_name' => 'Periabras',
            'last_name' => 'Ibo',
            'email' => 'kaibo@my.cspc.edu.ph',
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'emailAddress' => 'fake@gmail.com',
            'firstName' => 'Juan',
            'middleName' => 'Dela',
            'lastName' => 'Cruz',
        ]))->assertRedirect(route('chatbot.main'));

        $expectedReason = HouseholdRecordRequestMatcher::accountIdentityDenialReason($account, false, false);

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame($expectedReason, $row->decision_reason);
        $this->assertStringContainsString(
            'The submitted name and email address do not match the requester\'s registered chatbot account information.',
            $row->decision_reason
        );
        $this->assertStringContainsString('Kathlyn Periabras Ibo (kaibo@my.cspc.edu.ph)', $row->decision_reason);
        $this->assertStringNotContainsString('Juan Dela Cruz', $row->decision_reason);
        $this->assertStringNotContainsString('fake@gmail.com', $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNull($row->approved_at);
        $this->assertDatabaseCount('record_request_otps', 0);
        Mail::assertNothingOutgoing();
    }

    public function test_request_form_email_is_manual_entry_not_autofilled_or_readonly(): void
    {
        $this->actingAsResidentAccount(['email' => 'kaibo@my.cspc.edu.ph']);

        $html = $this->get(route('chatbot.household.verification'))->assertOk()->getContent();
        $this->assertStringContainsString('name="emailAddress"', $html);
        $this->assertStringNotContainsString('value="kaibo@my.cspc.edu.ph"', $html);
        $this->assertDoesNotMatchRegularExpression('/name="emailAddress"[^>]*\breadonly\b/', $html);
        $this->assertStringContainsString('placeholder="name@example.com"', $html);
    }

    public function test_same_name_in_another_household_is_not_selected(): void
    {
        $this->officialHouseholdResident('HH-001', [
            'first_name' => 'Other',
            'middle_name' => 'Person',
            'last_name' => 'Here',
        ]);
        [, $otherResident] = $this->officialHouseholdResident('HH-002');
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'householdNo' => 'HH-001',
        ]))->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $row->decision_reason);
        $this->assertSame(
            'The provided details do not correspond with an existing resident record.',
            $row->decision_reason,
        );
        $this->assertNull($row->matched_resident_id);
        $this->assertNotEquals($otherResident->getKey(), $row->matched_resident_id);
    }

    public function test_multiple_matches_in_household_are_denied_without_picking_one(): void
    {
        $household = Household::factory()->create(['household_no' => 'HH-001']);
        $first = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);
        $second = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);
        $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_DENIED, $row->status);
        $this->assertSame(HouseholdRecordRequestMatcher::REASON_DENIED, $row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNotEquals($first->getKey(), $row->matched_resident_id);
        $this->assertNotEquals($second->getKey(), $row->matched_resident_id);
        $this->assertNull($row->approved_at);
    }

    public function test_empty_official_data_leaves_request_pending(): void
    {
        $account = $this->actingAsResidentAccount();

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_PENDING, $row->status);
        $this->assertNull($row->decision_reason);
        $this->assertNull($row->matched_resident_id);
        $this->assertNull($row->evaluated_at);
        $this->assertNull($row->approved_at);
        $this->assertNull($account->fresh()->resident_id);
        $this->assertDatabaseCount('households', 0);
        $this->assertDatabaseCount('residents', 0);

        $html = $this->get(route('chatbot.main'))->assertOk()->getContent();
        $this->assertStringContainsString('Request Sent', $html);
        $this->assertStringNotContainsString('Access Household Record', $html);
    }

    public function test_trimmed_case_insensitive_names_and_household_no_match(): void
    {
        $household = Household::factory()->create(['household_no' => 'hh-001']);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => ' ANA ',
            'middle_name' => 'cruz',
            'last_name' => 'SANTOS',
        ]);
        $this->actingAsResidentAccount([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);

        $this->post(route('chatbot.household.verification.store'), $this->validPayload([
            'householdNo' => ' HH-001 ',
            'firstName' => 'ana',
            'middleName' => 'CRUZ',
            'lastName' => 'santos',
        ]))->assertRedirect(route('chatbot.household.verification.sms'));

        $row = RecordRequest::query()->first();
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $row->status);
        $this->assertSame((int) $resident->getKey(), (int) $row->matched_resident_id);
        $this->assertNull($row->approved_at);
        Http::assertSentCount(1);
    }

    public function test_invalid_form_still_creates_no_row(): void
    {
        $this->officialHouseholdResident('HH-001');
        $this->actingAsResidentAccount();

        $this->from(route('chatbot.household.verification'))
            ->post(route('chatbot.household.verification.store'), $this->validPayload([
                'householdNo' => '',
            ]))
            ->assertRedirect(route('chatbot.household.verification'))
            ->assertSessionHasErrors('householdNo');

        $this->assertDatabaseCount('record_requests', 0);
    }

    public function test_duplicate_pending_does_not_re_evaluate(): void
    {
        $account = $this->actingAsResidentAccount();
        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $this->assertSame(RecordRequest::STATUS_PENDING, RecordRequest::query()->value('status'));

        $this->officialHouseholdResident('HH-001');

        $this->post(route('chatbot.household.verification.store'), $this->validPayload())
            ->assertRedirect(route('chatbot.main'));

        $this->assertDatabaseCount('record_requests', 1);
        $this->assertSame(RecordRequest::STATUS_PENDING, RecordRequest::query()->value('status'));
        $this->assertNull(RecordRequest::query()->value('matched_resident_id'));
        $this->assertNull($account->fresh()->resident_id);
    }
}
