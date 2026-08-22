<?php

namespace App\Support;

use App\Models\ChildBirthHistory;
use App\Models\Resident;

/**
 * DB-06 Phase 2 — Birth History read/write for persisted residents.
 */
final class ChildBirthHistoryService
{
    public const PCAB_AT_LEAST_2_DOSES = 'at_least_2_doses_1_month_prior';

    public const PCAB_TT3_TD3_TO_TT5_TD5 = 'tt3_td3_to_tt5_td5_prior';

    /**
     * @return array<string, string>
     */
    public static function pcabLabels(): array
    {
        return [
            self::PCAB_AT_LEAST_2_DOSES => 'At least 2 doses received at least 1 month prior to delivery',
            self::PCAB_TT3_TD3_TO_TT5_TD5 => 'TT3/TD3 – TT5/TD5 given to the mother anytime prior to delivery',
        ];
    }

    /**
     * Upsert birth history for a persisted resident. resident_id is always derived server-side.
     *
     * @param  array{
     *     birth_weight?: string|float|null,
     *     birth_length?: string|float|null,
     *     pcab?: string|null,
     *     breastfeeding_date?: string|null
     * }  $payload
     */
    public function saveForResident(Resident $resident, array $payload): ChildBirthHistory
    {
        if ((int) $resident->id <= 0) {
            abort(404, 'Resident was not found.');
        }

        $weight = $this->nullableDecimal($payload['birth_weight'] ?? null);
        $length = $this->nullableDecimal($payload['birth_length'] ?? null);
        $pcab = $this->nullableString($payload['pcab'] ?? null);
        $bfDate = $this->nullableString($payload['breastfeeding_date'] ?? null);

        $attributes = [
            'birth_weight_kg' => $weight,
            'birth_length_cm' => $length,
            'status' => self::deriveStatus($weight),
            'pcab' => $pcab,
            'breastfeeding_date' => $bfDate !== null && $bfDate !== '' ? $bfDate : null,
        ];

        return ChildBirthHistory::query()->updateOrCreate(
            ['resident_id' => $resident->id],
            $attributes
        );
    }

    /**
     * Map a DB record to the frozen demoMember birth_history presentation shape.
     *
     * @return array{weight: string, length: string, status: string, pcab: string, breastfeeding_date: string}
     */
    public static function toPresentation(ChildBirthHistory $record): array
    {
        $weight = $record->birth_weight_kg;
        $length = $record->birth_length_cm;

        return [
            'weight' => $weight !== null ? (string) $weight : '',
            'length' => $length !== null ? (string) $length : '',
            'status' => (string) ($record->status ?? ''),
            'pcab' => self::pcabDisplayLabel($record->pcab),
            'breastfeeding_date' => $record->breastfeeding_date instanceof \Illuminate\Support\Carbon
                ? $record->breastfeeding_date->format('Y-m-d')
                : (string) ($record->breastfeeding_date ?? ''),
        ];
    }

    /**
     * Form-safe pcab value (stored key, not display label).
     */
    public static function pcabFormValue(?ChildBirthHistory $record): string
    {
        if ($record === null || $record->pcab === null || $record->pcab === '') {
            return '';
        }

        return (string) $record->pcab;
    }

    public static function pcabDisplayLabel(?string $pcab): string
    {
        if ($pcab === null || $pcab === '') {
            return '';
        }

        return self::pcabLabels()[$pcab] ?? $pcab;
    }

    public static function deriveStatus(?string $weight): ?string
    {
        if ($weight === null || $weight === '') {
            return null;
        }

        $kg = (float) $weight;
        if ($kg <= 0) {
            return null;
        }

        return $kg < 2.5 ? 'Low Birth Weight' : 'Normal';
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
