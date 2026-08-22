<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;

/**
 * DB-05 Phase 3 — presentation-compatible member identity for health destinations.
 *
 * DB-first via HouseholdMemberResolver; DemoCatalog read fallback; never materializes.
 * Returns the frozen Blade shape (demoHousehold / demoMember arrays) plus optional models.
 */
final class HealthMemberIdentity
{
    public function __construct(
        private readonly HouseholdMemberResolver $resolver,
    ) {}

    /**
     * @return array{
     *     source: 'db'|'demo'|null,
     *     household: array<string, mixed>|null,
     *     member: array<string, mixed>|null,
     *     householdNo: string,
     *     memberId: string,
     *     resident: Resident|null,
     *     householdModel: Household|null
     * }
     */
    public function resolve(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);

        $resolved = $this->resolver->resolveMember($hh, $mb);
        if ($resolved === null) {
            return [
                'source' => null,
                'household' => null,
                'member' => null,
                'householdNo' => $hh,
                'memberId' => $mb,
                'resident' => null,
                'householdModel' => null,
            ];
        }

        return [
            'source' => $resolved['source'],
            'household' => $resolved['householdPresentation'],
            'member' => $resolved['memberPresentation'],
            'householdNo' => $hh,
            'memberId' => $mb,
            'resident' => $resolved['resident'],
            'householdModel' => $resolved['household'],
        ];
    }

    /**
     * Fail-closed identity for DB death writes. Never returns demo-only members.
     *
     * @return array{
     *     source: 'db',
     *     household: array<string, mixed>,
     *     member: array<string, mixed>,
     *     householdNo: string,
     *     memberId: string,
     *     resident: Resident,
     *     householdModel: Household
     * }
     */
    public function resolvePersistedOrFail(string $householdNo, string $memberId): array
    {
        $ctx = $this->resolve($householdNo, $memberId);

        if ($ctx['source'] !== 'db'
            || $ctx['resident'] === null
            || $ctx['householdModel'] === null
            || $ctx['household'] === null
            || $ctx['member'] === null) {
            abort(404, 'Resident was not found.');
        }

        return [
            'source' => 'db',
            'household' => $ctx['household'],
            'member' => $ctx['member'],
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
            'resident' => $ctx['resident'],
            'householdModel' => $ctx['householdModel'],
        ];
    }
}
