<?php

namespace App\Services;

use App\Models\Household;
use App\Models\Resident;
use App\Support\DemoCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DB-05 Phase 2 — Resident create/update, member_no allocation, Head invariant.
 */
final class ResidentService
{
    private const MAX_MEMBER_NO_ATTEMPTS = 8;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(Household $household, array $validated): Resident
    {
        $payload = $this->normalizePayload($validated);

        return DB::transaction(function () use ($household, $payload): Resident {
            $locked = Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();

            if (($payload['relation'] ?? '') === 'Head') {
                $this->assertNoActiveHead($locked->id);
            }

            $attempt = 0;
            while ($attempt < self::MAX_MEMBER_NO_ATTEMPTS) {
                $attempt++;
                $memberNo = $this->allocateNextMemberNo();

                try {
                    return Resident::query()->create([
                        ...$payload,
                        'household_id' => $locked->id,
                        'member_no' => $memberNo,
                    ]);
                } catch (QueryException $e) {
                    if (! $this->isUniqueConstraintViolation($e) || $attempt >= self::MAX_MEMBER_NO_ATTEMPTS) {
                        throw $e;
                    }
                }
            }

            throw ValidationException::withMessages([
                'member_no' => 'Unable to allocate a unique member number. Please try again.',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(Resident $resident, array $validated): Resident
    {
        $payload = $this->normalizePayload($validated);

        return DB::transaction(function () use ($resident, $payload): Resident {
            $lockedResident = Resident::query()->whereKey($resident->id)->lockForUpdate()->firstOrFail();
            Household::query()->whereKey($lockedResident->household_id)->lockForUpdate()->firstOrFail();

            $newRelation = (string) ($payload['relation'] ?? $lockedResident->relation);
            $wasHead = strcasecmp((string) $lockedResident->relation, 'Head') === 0;
            $becomesHead = strcasecmp($newRelation, 'Head') === 0;

            if ($becomesHead && ! $wasHead) {
                $this->assertNoActiveHead($lockedResident->household_id, $lockedResident->id);
            }

            unset($payload['household_id'], $payload['member_no'], $payload['id']);

            $lockedResident->fill($payload);
            $lockedResident->save();

            return $lockedResident->fresh();
        });
    }

    /**
     * Next MB-{n} considering DB (incl. soft-deleted) and DemoCatalog member IDs.
     */
    public function allocateNextMemberNo(): string
    {
        $max = 0;

        $dbNumbers = Resident::withTrashed()
            ->pluck('member_no')
            ->all();

        foreach ($dbNumbers as $memberNo) {
            $max = max($max, $this->numericSuffix((string) $memberNo));
        }

        foreach (DemoCatalog::households() as $household) {
            foreach ($household['memberList'] ?? [] as $member) {
                $max = max($max, $this->numericSuffix((string) ($member['id'] ?? '')));
            }
        }

        $next = $max + 1;

        return 'MB-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
    }

    private function assertNoActiveHead(int $householdId, ?int $exceptResidentId = null): void
    {
        $query = Resident::query()
            ->where('household_id', $householdId)
            ->where('relation', 'Head');

        if ($exceptResidentId !== null) {
            $query->where('id', '!=', $exceptResidentId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'relation' => 'This household already has an active Head. Change the existing Head first.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizePayload(array $validated): array
    {
        $philhealth = isset($validated['philhealth'])
            ? preg_replace('/\s+/', '', trim((string) $validated['philhealth']))
            : '';
        if ($philhealth === null || $philhealth === '') {
            $philhealth = null;
        }

        $middle = isset($validated['middle_name']) ? trim((string) $validated['middle_name']) : '';
        $middle = $middle === '' ? null : $middle;

        $disability = array_values(array_unique(array_map('strval', $validated['disability'] ?? [])));
        $medical = array_values(array_unique(array_map('strval', $validated['medical_history'] ?? [])));

        $disabilityOthers = in_array('others', $disability, true)
            ? trim((string) ($validated['disability_others'] ?? ''))
            : null;
        if ($disabilityOthers === '') {
            $disabilityOthers = null;
        }

        $medicalOthers = in_array('others', $medical, true)
            ? trim((string) ($validated['medical_others'] ?? ''))
            : null;
        if ($medicalOthers === '') {
            $medicalOthers = null;
        }

        return [
            'last_name' => trim((string) $validated['last_name']),
            'first_name' => trim((string) $validated['first_name']),
            'middle_name' => $middle,
            'relation' => (string) $validated['relation'],
            'birthday' => (string) $validated['birthday'],
            'sex' => (string) $validated['sex'],
            'relationship_status' => (string) $validated['relationship_status'],
            'occupation' => (string) $validated['occupation'],
            'monthly_income' => (string) $validated['monthly_income'],
            'religion' => (string) $validated['religion'],
            'education' => (string) $validated['education'],
            'fp_user' => (string) $validated['fp_user'],
            'philhealth' => $philhealth,
            'disability' => $disability,
            'disability_others' => $disabilityOthers,
            'medical_history' => $medical,
            'medical_others' => $medicalOthers,
        ];
    }

    private function numericSuffix(string $memberNo): int
    {
        if (preg_match('/^MB-(\d+)$/i', trim($memberNo), $m) !== 1) {
            return 0;
        }

        return (int) $m[1];
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'member_no')
            || (string) $e->getCode() === '23000';
    }
}
