<?php

namespace App\Support;

use App\Models\Household;
use App\Models\Resident;
use Illuminate\Support\Carbon;

/**
 * Maps Eloquent Household/Resident to the frozen Blade presentation shape
 * previously supplied by resources/demo/households.php.
 */
final class HouseholdProfilingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(Household $household): array
    {
        $household->loadMissing('residents');
        $residents = $household->residents;
        $head = $residents->first(
            static fn (Resident $r): bool => strcasecmp((string) $r->relation, 'Head') === 0
        );

        $memberList = $residents
            ->map(static fn (Resident $r): array => self::memberFromModel($r))
            ->values()
            ->all();

        $householdNo = (string) $household->household_no;
        $displayNo = preg_replace('/^HH-/i', 'HH ', $householdNo) ?: $householdNo;

        return [
            'householdNo' => $householdNo,
            'displayNo' => $displayNo,
            'houseHead' => $head ? self::fullName($head) : '—',
            'zone' => (string) $household->zone,
            'street' => (string) $household->street,
            'address' => $household->address !== null && $household->address !== ''
                ? (string) $household->address
                : trim($household->street.', Brgy. La Medalla'),
            'purok' => (string) $household->zone,
            'members' => count($memberList),
            'lat' => $household->latitude !== null ? (float) $household->latitude : null,
            'lng' => $household->longitude !== null ? (float) $household->longitude : null,
            'mapStatus' => 'plotted',
            'accomplishedBy' => $household->accomplished_by !== null && $household->accomplished_by !== ''
                ? (string) $household->accomplished_by
                : '—',
            'accomplishedDate' => $household->date_registered instanceof Carbon
                ? $household->date_registered->format('F j, Y')
                : '—',
            // Amenities are out of scope for households table — placeholders for frozen Blade.
            'water' => [
                'title' => 'Access to Safe Water',
                'level' => '—',
                'status' => 'Not recorded',
            ],
            'sanitation' => [
                'title' => 'Sanitation Services',
                'facility' => '—',
                'status' => 'Not recorded',
            ],
            'memberList' => $memberList,
        ];
    }

    /**
     * @return array{id: string, householdNo: string, houseHead: string, zone: string, street: string, members: int}
     */
    public static function listRowFromModel(Household $household): array
    {
        $presentation = self::fromModel($household);

        return [
            'id' => 'db-'.strtolower((string) $household->household_no),
            'householdNo' => (string) $presentation['householdNo'],
            'houseHead' => (string) $presentation['houseHead'],
            'zone' => (string) $presentation['zone'],
            'street' => (string) $presentation['street'],
            'members' => (int) $presentation['members'],
        ];
    }

    /**
     * @param  array<string, mixed>  $demoHousehold
     * @return array{id: string, householdNo: string, houseHead: string, zone: string, street: string, members: int}
     */
    public static function listRowFromDemo(array $demoHousehold): array
    {
        $no = (string) ($demoHousehold['householdNo'] ?? '');

        return [
            'id' => 'demo-'.strtolower($no),
            'householdNo' => $no,
            'houseHead' => (string) ($demoHousehold['houseHead'] ?? '—'),
            'zone' => (string) ($demoHousehold['zone'] ?? ''),
            'street' => (string) ($demoHousehold['street'] ?? ''),
            'members' => (int) ($demoHousehold['members'] ?? count($demoHousehold['memberList'] ?? [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function memberFromModel(Resident $resident): array
    {
        $birthday = $resident->birthday;
        $age = $birthday instanceof Carbon ? $birthday->age : null;

        return [
            'id' => (string) $resident->member_no,
            'name' => self::fullName($resident),
            'relationship' => (string) $resident->relation,
            'age' => $age,
            'sex' => (string) $resident->sex,
            'occupation' => (string) $resident->occupation,
            'last_name' => (string) $resident->last_name,
            'first_name' => (string) $resident->first_name,
            'middle_name' => (string) ($resident->middle_name ?? ''),
            'relation' => (string) $resident->relation,
            'birthday' => $birthday instanceof Carbon ? $birthday->format('Y-m-d') : (string) $birthday,
            'relationship_status' => (string) $resident->relationship_status,
            'monthly_income' => (string) $resident->monthly_income,
            'religion' => (string) $resident->religion,
            'education' => (string) $resident->education,
            'philhealth' => $resident->philhealth ?? '',
            'fp_user' => (string) $resident->fp_user,
            'disability' => is_array($resident->disability) ? $resident->disability : [],
            'disability_others' => (string) ($resident->disability_others ?? ''),
            'medical_history' => is_array($resident->medical_history) ? $resident->medical_history : [],
            'medical_others' => (string) ($resident->medical_others ?? ''),
        ];
    }

    public static function fullName(Resident $resident): string
    {
        $parts = array_filter([
            trim((string) $resident->first_name),
            trim((string) ($resident->middle_name ?? '')),
            trim((string) $resident->last_name),
        ], static fn (string $p): bool => $p !== '');

        return implode(' ', $parts);
    }
}
