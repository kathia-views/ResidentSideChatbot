<?php

namespace App\Http\Controllers\Chatbot;

use App\Http\Controllers\Controller;
use App\Models\RecordRequest;
use App\Models\ResidentAccount;
use App\Support\ChatbotHouseholdNumberDisplay;
use App\Support\HouseholdRecordVerifiedAccess;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Authorized household-member view for chatbot accounts whose latest
 * owned record request is Approved. Household scope is derived only from
 * session account → linked resident → household (never from request input).
 */
class HouseholdInformationController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $account = $request->attributes->get('residentAccount');

        abort_unless($account instanceof ResidentAccount, 403);

        $payload = $this->authorizedHouseholdPayload($account);

        if ($payload === null) {
            return redirect()->route('chatbot.main');
        }

        return view('pages.chatbot.household-information', $payload);
    }

    /**
     * @return array{
     *     residentDisplayName: string,
     *     householdDisplayNo: string,
     *     members: list<array<string, string>>,
     *     summaryAdults: int,
     *     summaryYouth: int,
     *     summaryChildren: int
     * }|null
     */
    private function authorizedHouseholdPayload(ResidentAccount $account): ?array
    {
        $record = RecordRequest::latestForAccount($account->account_id);

        if (
            ! $record instanceof RecordRequest
            || ! HouseholdRecordVerifiedAccess::grantsHouseholdInformationAccess($account, $record)
        ) {
            return null;
        }

        $residentKey = $this->tableIdentityColumn('residents', 'resident_id', 'id');
        $householdKey = $this->tableIdentityColumn('households', 'household_id', 'id');

        if (
            $residentKey === null
            || $householdKey === null
            || ! Schema::hasColumn('residents', 'household_id')
            || ! Schema::hasColumn('households', 'household_no')
        ) {
            return null;
        }

        $linkedResident = DB::table('residents')
            ->where($residentKey, $account->resident_id)
            ->when(Schema::hasColumn('residents', 'deleted_at'), static fn ($query) => $query->whereNull('deleted_at'))
            ->first();

        if ($linkedResident === null || ! isset($linkedResident->household_id) || $linkedResident->household_id === null) {
            return null;
        }

        $household = DB::table('households')
            ->where($householdKey, $linkedResident->household_id)
            ->when(Schema::hasColumn('households', 'deleted_at'), static fn ($query) => $query->whereNull('deleted_at'))
            ->first();

        if ($household === null) {
            return null;
        }

        $householdNo = trim((string) ($household->household_no ?? ''));

        if ($householdNo === '') {
            return null;
        }

        $authorizedHouseholdId = $linkedResident->household_id;

        // Authoritative membership: residents.household_id only (never member_no).
        $memberRows = DB::table('residents')
            ->where('household_id', $authorizedHouseholdId)
            ->when(Schema::hasColumn('residents', 'deleted_at'), static fn ($query) => $query->whereNull('deleted_at'))
            ->get();

        $orderedRows = $this->orderHouseholdMembers($memberRows, $residentKey);

        $members = [];
        $summaryAdults = 0;
        $summaryYouth = 0;
        $summaryChildren = 0;

        foreach ($orderedRows as $row) {
            $members[] = $this->mapMemberRow($row, $residentKey);

            $years = $this->ageInYears($row->birthday ?? null);

            if ($years === null) {
                continue;
            }

            if ($years >= 18) {
                $summaryAdults++;
            } elseif ($years >= 13) {
                $summaryYouth++;
            } else {
                $summaryChildren++;
            }
        }

        return [
            'residentDisplayName' => $this->displayName($account),
            'householdDisplayNo' => ChatbotHouseholdNumberDisplay::format($householdNo),
            'members' => $members,
            'summaryAdults' => $summaryAdults,
            'summaryYouth' => $summaryYouth,
            'summaryChildren' => $summaryChildren,
        ];
    }

    /**
     * Presentation order only — membership remains household_id scoped.
     * Priority uses actual residents.relation vocabulary; birthday older→younger;
     * resident PK is the deterministic tie-breaker. Never uses member_no.
     *
     * @param  Collection<int, object>  $rows
     * @return list<object>
     */
    private function orderHouseholdMembers(Collection $rows, string $residentKey): array
    {
        return $rows
            ->sort(function (object $a, object $b) use ($residentKey): int {
                $priorityCompare = $this->relationPresentationPriority((string) ($a->relation ?? ''))
                    <=> $this->relationPresentationPriority((string) ($b->relation ?? ''));

                if ($priorityCompare !== 0) {
                    return $priorityCompare;
                }

                $birthdayCompare = $this->birthdaySortKey($a->birthday ?? null)
                    <=> $this->birthdaySortKey($b->birthday ?? null);

                if ($birthdayCompare !== 0) {
                    return $birthdayCompare;
                }

                return strcmp(
                    (string) ($a->{$residentKey} ?? $a->id ?? ''),
                    (string) ($b->{$residentKey} ?? $b->id ?? ''),
                );
            })
            ->values()
            ->all();
    }

    /**
     * Lower rank sorts first. Built from LMLINGA relation values (Head, Spouse, …).
     */
    private function relationPresentationPriority(string $relation): int
    {
        $normalized = mb_strtolower(trim($relation), 'UTF-8');

        if (in_array($normalized, ['head', 'head of household', 'household head'], true)) {
            return 0;
        }

        if (in_array($normalized, ['spouse', 'partner', 'live-in', 'live in'], true)) {
            return 1;
        }

        if (in_array($normalized, ['parent', 'father', 'mother'], true)) {
            return 2;
        }

        if (in_array($normalized, ['son', 'daughter', 'grandchild', 'child', 'children'], true)) {
            return 4;
        }

        if (in_array($normalized, [
            'sibling',
            'grandparent',
            'other relative',
            'relative',
        ], true)) {
            return 3;
        }

        if (in_array($normalized, [
            'non-relative',
            'non-relative household member',
            'non relative',
            'other',
            '',
        ], true)) {
            return 5;
        }

        return 3;
    }

    private function birthdaySortKey(mixed $birthday): int
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }

        return $date->getTimestamp();
    }

    /**
     * @param  object  $row
     * @return array<string, string>
     */
    private function mapMemberRow(object $row, string $residentKey): array
    {
        $pk = (string) ($row->{$residentKey} ?? $row->id ?? '');
        $anchorId = 'member-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $pk);

        $first = trim((string) ($row->first_name ?? ''));
        $middle = trim((string) ($row->middle_name ?? ''));
        $last = trim((string) ($row->last_name ?? ''));
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        $relation = trim((string) ($row->relation ?? ''));
        $relationshipLabel = $this->relationshipLabel($relation);

        $sex = trim((string) ($row->sex ?? ''));
        if ($sex === '') {
            $sex = 'N/A';
        }

        $civil = trim((string) ($row->relationship_status ?? ''));
        $occupation = trim((string) ($row->occupation ?? ''));

        return [
            'id' => $anchorId,
            'name' => $name !== '' ? $name : 'Resident',
            'age' => $this->formatAge($row->birthday ?? null),
            'sex' => $sex,
            'relationship' => $relationshipLabel,
            'birthday' => $this->formatBirthday($row->birthday ?? null),
            'civilStatus' => $civil !== '' ? $civil : 'N/A',
            'occupation' => $occupation !== '' ? $occupation : 'N/A',
            'weight' => 'N/A',
            'height' => 'N/A',
            'nutrition' => 'N/A',
        ];
    }

    private function relationshipLabel(string $relation): string
    {
        $normalized = mb_strtolower(trim($relation), 'UTF-8');

        if (in_array($normalized, ['head', 'head of household', 'household head'], true)) {
            return 'Head of Household';
        }

        return $relation !== '' ? $relation : 'N/A';
    }

    private function formatAge(mixed $birthday): string
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return 'N/A';
        }

        $now = now()->startOfDay();
        $birth = $date->copy()->startOfDay();

        if ($birth->greaterThan($now)) {
            return 'N/A';
        }

        $months = (int) $birth->diffInMonths($now);

        if ($months < 12) {
            $months = max($months, 0);

            return $months === 1 ? '1 month old' : $months.' months old';
        }

        $years = (int) $birth->diffInYears($now);

        return $years === 1 ? '1 year old' : $years.' years old';
    }

    private function ageInYears(mixed $birthday): ?int
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return null;
        }

        $now = now()->startOfDay();
        $birth = $date->copy()->startOfDay();

        if ($birth->greaterThan($now)) {
            return null;
        }

        return (int) $birth->diffInYears($now);
    }

    private function formatBirthday(mixed $birthday): string
    {
        try {
            $date = $birthday instanceof CarbonInterface
                ? $birthday
                : Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return 'N/A';
        }

        return $date->format('F j, Y');
    }

    private function displayName(ResidentAccount $account): string
    {
        $first = trim((string) $account->first_name);
        $middle = trim((string) $account->middle_name);
        $last = trim((string) $account->last_name);
        $name = trim(implode(' ', array_filter([$first, $middle, $last], static fn (string $part): bool => $part !== '')));

        return $name !== '' ? $name : 'Resident';
    }

    private function tableIdentityColumn(string $table, string $livePrimaryKey, string $sqlitePrimaryKey): ?string
    {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }

            if (Schema::hasColumn($table, $livePrimaryKey)) {
                return $livePrimaryKey;
            }

            if (Schema::hasColumn($table, $sqlitePrimaryKey)) {
                return $sqlitePrimaryKey;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
