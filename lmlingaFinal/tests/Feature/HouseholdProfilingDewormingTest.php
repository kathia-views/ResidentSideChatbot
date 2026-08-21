<?php

namespace Tests\Feature;

use App\Support\DemoCatalog;
use App\Support\HealthRecordsChildCare;
use App\Support\HealthRecordsDeworming;
use App\Support\UiRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Household Profiling → Member → Child Care → Deworming resident workflow.
 *
 * Deworming is available for ALL household members (all ages). Child Care
 * 0–59 month population rules still apply to other Child Care services only.
 */
class HouseholdProfilingDewormingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private const HOUSEHOLD_NO = 'HH-151';

    /** Infant / Child Care population fixture (Kristine B. Reyes). */
    private const INFANT_MEMBER_ID = 'MB-009';

    /** Adult household head (Kristine Reyes) — previously “ineligible”. */
    private const ADULT_MEMBER_ID = 'MB-001';

    /** Older child (~5 yrs / past 59 months as of 2026) — Angelo David Reyes. */
    private const OLDER_CHILD_MEMBER_ID = 'MB-003';

    /** @return array{householdNo: string, memberId: string} */
    private function memberParams(string $memberId): array
    {
        return [
            'householdNo' => self::HOUSEHOLD_NO,
            'memberId' => $memberId,
        ];
    }

    public function test_deworming_member_routes_resolve(): void
    {
        $this->assertTrue(Route::has('household-profiling.members.deworming'));
        $this->assertTrue(Route::has('household-profiling.members.deworming.create'));

        $params = $this->memberParams(self::INFANT_MEMBER_ID);

        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-009/deworming'),
            route('household-profiling.members.deworming', $params)
        );
        $this->assertSame(
            url('/household-profiling/HH-151/members/MB-009/deworming/create'),
            route('household-profiling.members.deworming.create', $params)
        );
    }

    public function test_deworming_is_available_for_all_ages_while_child_care_population_stays_0_to_59(): void
    {
        $this->assertSame(59, HealthRecordsChildCare::MAX_AGE_MONTHS);

        $now = Carbon::parse('2026-08-17')->startOfDay();
        Carbon::setTestNow($now);

        $infant = ['birthday' => $now->copy()->subMonthsNoOverflow(6)->toDateString()];
        $at59Months = ['birthday' => $now->copy()->subMonthsNoOverflow(59)->toDateString()];
        $at60Months = ['birthday' => $now->copy()->subMonthsNoOverflow(60)->toDateString()];
        $adolescent = ['birthday' => $now->copy()->subYearsNoOverflow(14)->toDateString()];
        $adult = ['birthday' => $now->copy()->subYearsNoOverflow(35)->toDateString()];
        $senior = ['birthday' => $now->copy()->subYearsNoOverflow(70)->toDateString()];

        $this->assertSame(59, HealthRecordsChildCare::ageInMonths($at59Months));
        $this->assertSame(60, HealthRecordsChildCare::ageInMonths($at60Months));

        // Child Care population boundary remains authoritative for other services.
        $this->assertTrue(HealthRecordsChildCare::isChildCarePopulation($infant));
        $this->assertTrue(HealthRecordsChildCare::isChildCarePopulation($at59Months));
        $this->assertFalse(HealthRecordsChildCare::isChildCarePopulation($at60Months));
        $this->assertFalse(HealthRecordsChildCare::isChildCarePopulation($adolescent));
        $this->assertFalse(HealthRecordsChildCare::isChildCarePopulation($adult));
        $this->assertFalse(HealthRecordsChildCare::isChildCarePopulation($senior));

        // Deworming: all ages.
        foreach ([$infant, $at59Months, $at60Months, $adolescent, $adult, $senior] as $member) {
            $this->assertTrue(
                HealthRecordsDeworming::memberCanManageRecords($member),
                'Deworming must allow all ages; failed for birthday '.$member['birthday']
            );
        }

        $this->assertFalse(HealthRecordsDeworming::memberCanManageRecords([]));
    }

    public function test_fixture_members_across_ages_can_manage_deworming(): void
    {
        $household = DemoCatalog::findHousehold(self::HOUSEHOLD_NO);
        $this->assertNotNull($household);

        foreach ([self::INFANT_MEMBER_ID, self::OLDER_CHILD_MEMBER_ID, self::ADULT_MEMBER_ID] as $memberId) {
            $member = lml_demo_find_member($household, $memberId);
            $this->assertNotNull($member, 'Missing fixture member '.$memberId);
            $this->assertTrue(HealthRecordsDeworming::memberCanManageRecords($member));
            $this->assertNotNull(HealthRecordsDeworming::findChildForMember(self::HOUSEHOLD_NO, $memberId));
        }
    }

    public function test_individual_deworming_page_scopes_to_selected_infant_member(): void
    {
        $params = $this->memberParams(self::INFANT_MEMBER_ID);
        $showUrl = route('household-profiling.members.deworming', $params);
        $createUrl = route('household-profiling.members.deworming.create', $params);
        $memberUrl = route('household-profiling.members.show', $params);

        $html = $this->get($showUrl)->assertOk()->getContent();

        $this->assertStringContainsString('data-lml-hr-dw-record', $html);
        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-009"', $html);
        $this->assertStringContainsString('Kristine B. Reyes', $html);
        $this->assertStringContainsString('May 4, 2026', $html);
        $this->assertStringNotContainsString('College Graduate', $html);
        $this->assertStringContainsString('data-hr-dw-add-record', $html);
        $this->assertStringContainsString('href="'.e($createUrl).'"', $html);
        $this->assertStringContainsString('href="'.e($memberUrl).'"', $html);
        $this->assertStringContainsString('aria-label="Back to member health records"', $html);
        $this->assertStringContainsString('2026', $html);
        $this->assertStringContainsString('NHTS', $html);
        $this->assertSame('household-profiling', UiRole::sidebarActiveKey());
    }

    public function test_adult_member_resolves_own_identity_and_can_add_record(): void
    {
        $params = $this->memberParams(self::ADULT_MEMBER_ID);
        $showUrl = route('household-profiling.members.deworming', $params);
        $createUrl = route('household-profiling.members.deworming.create', $params);

        $html = $this->get($showUrl)->assertOk()->getContent();

        $this->assertStringContainsString('data-household-no="HH-151"', $html);
        $this->assertStringContainsString('data-member-id="MB-001"', $html);
        $this->assertStringContainsString('Kristine Reyes', $html);
        $this->assertStringContainsString('May 4, 1991', $html);
        $this->assertStringContainsString('College Graduate', $html);
        $this->assertStringNotContainsString('Kristine B. Reyes', $html);
        $this->assertStringContainsString('data-hr-dw-add-record', $html);
        $this->assertStringContainsString('href="'.e($createUrl).'"', $html);
    }

    public function test_similar_names_do_not_cross_contaminate_deworming_history(): void
    {
        $infantRecords = HealthRecordsDeworming::recordsForMember(
            self::HOUSEHOLD_NO,
            self::INFANT_MEMBER_ID
        );
        $adultRecords = HealthRecordsDeworming::recordsForMember(
            self::HOUSEHOLD_NO,
            self::ADULT_MEMBER_ID
        );

        $this->assertNotSame([], $infantRecords);
        $this->assertSame([], $adultRecords);

        $adultHtml = $this->get(route('household-profiling.members.deworming', $this->memberParams(self::ADULT_MEMBER_ID)))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('January 20, 2026', $adultHtml);
        $this->assertStringNotContainsString('July 1, 2026', $adultHtml);
    }

    public function test_add_record_form_preserves_member_context_for_infant_and_adult(): void
    {
        foreach ([self::INFANT_MEMBER_ID, self::ADULT_MEMBER_ID] as $memberId) {
            $params = $this->memberParams($memberId);
            $showUrl = route('household-profiling.members.deworming', $params);
            $createUrl = route('household-profiling.members.deworming.create', $params);

            $html = $this->get($createUrl)->assertOk()->getContent();

            $this->assertStringContainsString('data-lml-hr-dw-mode="create"', $html);
            $this->assertStringContainsString('data-household-no="HH-151"', $html);
            $this->assertStringContainsString('data-member-id="'.$memberId.'"', $html);
            $this->assertStringContainsString('Add Deworming Record', $html);
            $this->assertStringContainsString('href="'.e($showUrl).'"', $html);
            $this->assertStringContainsString('data-hr-dw-return="'.e($showUrl).'"', $html);
            $this->assertStringContainsString('data-hr-dw-cancel', $html);
            $this->assertStringContainsString('data-hr-dw-save', $html);
            $this->assertStringNotContainsString(
                route('health-records.child-care.deworming.create', ['childKey' => 'kristine-b-reyes']),
                $html
            );
        }
    }

    public function test_member_view_shows_deworming_link_for_infant_and_adult(): void
    {
        foreach ([self::INFANT_MEMBER_ID, self::ADULT_MEMBER_ID, self::OLDER_CHILD_MEMBER_ID] as $memberId) {
            $params = $this->memberParams($memberId);
            $dewormingUrl = route('household-profiling.members.deworming', $params);

            $html = $this->get(route('household-profiling.members.show', $params))
                ->assertOk()
                ->getContent();

            $this->assertSame(1, substr_count($html, '>Deworming</'));
            $this->assertStringContainsString('href="'.e($dewormingUrl).'"', $html);
            $this->assertStringContainsString('bi-capsule', $html);
        }
    }

    public function test_other_child_care_links_remain_intact_on_member_page(): void
    {
        $params = $this->memberParams(self::INFANT_MEMBER_ID);

        $html = $this->get(route('household-profiling.members.show', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.child-immunization', $params)).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.school-based-immunization', $params)).'"',
            $html
        );
        $this->assertStringContainsString(
            'href="'.e(route('household-profiling.members.child-nutrition', $params)).'"',
            $html
        );
    }

    public function test_invalid_member_fails_safely_without_add(): void
    {
        $params = [
            'householdNo' => self::HOUSEHOLD_NO,
            'memberId' => 'MB-999',
        ];

        $html = $this->get(route('household-profiling.members.deworming', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-hr-dw-add-record', $html);
        $this->assertStringContainsString('Demo member was not found.', $html);

        $this->get(route('household-profiling.members.deworming.create', $params))
            ->assertRedirect(route('household-profiling.members.deworming', $params));
    }

    public function test_cross_household_member_does_not_resolve(): void
    {
        // MB-001 exists on HH-151 only; requesting it under another household must not load that person.
        $params = [
            'householdNo' => 'HH-152',
            'memberId' => self::ADULT_MEMBER_ID,
        ];

        $html = $this->get(route('household-profiling.members.deworming', $params))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-hr-dw-add-record', $html);
        $this->assertStringContainsString('Demo member was not found.', $html);
        $this->assertStringNotContainsString('May 4, 1991', $html);

        $this->assertNull(HealthRecordsDeworming::findChildForMember('HH-152', self::ADULT_MEMBER_ID));
        $this->assertSame([], HealthRecordsDeworming::recordsForMember('HH-152', self::ADULT_MEMBER_ID));
    }

    public function test_health_records_deworming_monitoring_unchanged_no_add_on_summary(): void
    {
        $this->assertTrue(Route::has('health-records.child-care.deworming'));

        $html = $this->get(route('health-records.child-care.deworming'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-lml-hr-deworming', $html);
        $this->assertStringContainsString('aria-label="Export Deworming data"', $html);
        $this->assertStringNotContainsString('data-hr-dw-add', $html);
        $this->assertStringNotContainsString('data-hr-dw-add-record', $html);
        $this->assertStringContainsString(
            'Record and management of deworming details for monitoring and tracking treatment status.',
            $html
        );
    }
}
