<?php

namespace App\Services;

use App\Models\Household;
use App\Support\DemoCatalog;
use App\Support\HouseholdProfilingPresenter;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 2 — Household Profiling list/read helpers (DB-first + demo merge).
 */
final class HouseholdService
{
    /**
     * @return list<array{id: string, householdNo: string, houseHead: string, zone: string, street: string, members: int}>
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

    private function householdsTableReady(): bool
    {
        try {
            return Schema::hasTable('households');
        } catch (\Throwable) {
            return false;
        }
    }
}
