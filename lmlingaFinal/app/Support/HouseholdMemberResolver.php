<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 2 — DB-first household/member resolution with DemoCatalog read fallback.
 * Fallback never persists. Member resolution is always household-scoped.
 */
final class HouseholdMemberResolver
{
    /**
     * @return array{source: 'db', household: Household, presentation: array<string, mixed>}|array{source: 'demo', household: null, presentation: array<string, mixed>}|null
     */
    public function resolveHousehold(string $householdNo): ?array
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);

        if ($this->householdsTableReady()) {
            $household = Household::query()
                ->where('household_no', $key)
                ->with(['residents' => static fn ($q) => $q->orderBy('id')])
                ->first();

            if ($household !== null) {
                return [
                    'source' => 'db',
                    'household' => $household,
                    'presentation' => HouseholdProfilingPresenter::fromModel($household),
                ];
            }
        }

        $demo = DemoCatalog::findHousehold($key);
        if ($demo === null) {
            return null;
        }

        return [
            'source' => 'demo',
            'household' => null,
            'presentation' => $demo,
        ];
    }

    /**
     * Active DB household only (writes). No DemoCatalog materialization.
     */
    public function resolveDbHouseholdOrFail(string $householdNo): Household
    {
        $key = DemoCatalog::normalizeHouseholdNo($householdNo);

        return Household::query()
            ->where('household_no', $key)
            ->firstOrFail();
    }

    /**
     * @return array{
     *     source: 'db'|'demo',
     *     household: Household|null,
     *     resident: Resident|null,
     *     householdPresentation: array<string, mixed>,
     *     memberPresentation: array<string, mixed>
     * }|null
     */
    public function resolveMember(string $householdNo, string $memberId): ?array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);

        $resolved = $this->resolveHousehold($hh);
        if ($resolved === null) {
            return null;
        }

        if ($resolved['source'] === 'db') {
            /** @var Household $household */
            $household = $resolved['household'];

            $resident = Resident::query()
                ->where('household_id', $household->id)
                ->where('member_no', $mb)
                ->first();

            if ($resident !== null) {
                return [
                    'source' => 'db',
                    'household' => $household,
                    'resident' => $resident,
                    'householdPresentation' => $resolved['presentation'],
                    'memberPresentation' => HouseholdProfilingPresenter::memberFromModel($resident),
                ];
            }

            return null;
        }

        $demoHousehold = $resolved['presentation'];
        $member = lml_demo_find_member($demoHousehold, $mb);
        if ($member === null) {
            return null;
        }

        return [
            'source' => 'demo',
            'household' => null,
            'resident' => null,
            'householdPresentation' => $demoHousehold,
            'memberPresentation' => $member,
        ];
    }

    /**
     * Active DB resident scoped to household (writes / edit).
     *
     * @return array{household: Household, resident: Resident}
     */
    public function resolveDbMemberOrFail(string $householdNo, string $memberId): array
    {
        $household = $this->resolveDbHouseholdOrFail($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);

        $resident = Resident::query()
            ->where('household_id', $household->id)
            ->where('member_no', $mb)
            ->firstOrFail();

        return [
            'household' => $household,
            'resident' => $resident,
        ];
    }

    private function householdsTableReady(): bool
    {
        try {
            return Schema::hasTable('households');
        } catch (\Throwable) {
            return false;
        }
    }
}
