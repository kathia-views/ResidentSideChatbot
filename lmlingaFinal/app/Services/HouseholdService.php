<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Resident;
use App\Support\DemoCatalog;
use App\Support\HouseholdProfilingPresenter;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 2/4 — Household Profiling list/read helpers (DB-first + demo merge).
 *
 * Summary cards count DB-backed registered records only (not DemoCatalog rows).
 */
final class HouseholdService
{
    /**
     * @return list<array{id: string, householdNo: string, houseHead: string, zone: string, street: string, members: int, source: 'db'|'demo'}>
     */
    public function profilingListRows(): array
    {
        $rowsByNo = [];

        if ($this->householdsTableReady()) {
            $dbHouseholds = Household::query()
                ->with(['residents' => static fn ($q) => $q->orderBy('id')])
                ->orderBy('household_no')
                ->get();

            foreach ($dbHouseholds as $household) {
                $row = HouseholdProfilingPresenter::listRowFromModel($household);
                $rowsByNo[$row['householdNo']] = $row;
            }
        }

        foreach (DemoCatalog::households() as $householdNo => $demo) {
            $key = DemoCatalog::normalizeHouseholdNo((string) $householdNo);
            if (isset($rowsByNo[$key])) {
                continue;
            }
            $rowsByNo[$key] = HouseholdProfilingPresenter::listRowFromDemo($demo);
        }

        ksort($rowsByNo, SORT_NATURAL);

        return array_values($rowsByNo);
    }

    /**
     * Registered (database) totals for summary cards.
     * Soft-deleted households/residents are excluded by Eloquent SoftDeletes.
     * DemoCatalog fallback rows are intentionally excluded.
     *
     * @return array{households: int, respondents: int, male: int, female: int}
     */
    public function profilingSummary(): array
    {
        if (! $this->householdsTableReady() || ! $this->residentsTableReady()) {
            return [
                'households' => 0,
                'respondents' => 0,
                'male' => 0,
                'female' => 0,
            ];
        }

        return [
            'households' => Household::query()->count(),
            'respondents' => Resident::query()->count(),
            'male' => Resident::query()->where('sex', 'Male')->count(),
            'female' => Resident::query()->where('sex', 'Female')->count(),
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

    private function residentsTableReady(): bool
    {
        try {
            return Schema::hasTable('residents');
        } catch (\Throwable) {
            return false;
        }
    }
}
