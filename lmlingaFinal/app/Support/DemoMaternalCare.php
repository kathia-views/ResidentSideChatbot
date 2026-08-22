<?php

namespace App\Support;

/**
 * Demo Maternal Care Phase 1 helpers.
 *
 * Session-backed preview state only (no database / migrations).
 * Active pregnancy + section payloads are stored per household+member.
 */
final class DemoMaternalCare
{
    public const SESSION_KEY = 'lml.demo.maternal_care.v1';

    public const OUTCOMES = [
        'FT' => 'Full Term',
        'PT' => 'Pre-Term',
        'FD' => 'Fetal Death',
        'AB' => 'Abortion',
    ];

    public const DELIVERY_TYPES = [
        'CS' => 'CS - Cesarean',
        'VD' => 'VD - Vaginal Delivery',
        'CVCD' => 'CVCD - Combined Vaginal Cesarean Delivery',
    ];

    public const BIRTH_ATTENDANTS = [
        'MD' => 'MD - Doctor',
        'RN' => 'RN - Nurse',
        'MW' => 'MW - Midwife',
        'Others' => 'Others',
    ];

    public const PLACES_OF_DELIVERY = [
        'public' => 'Public Health Facility',
        'private' => 'Private Health Facility',
        'non_health' => 'Non-Health Facility',
    ];

    public const HEPATITIS_B_RESULTS = [
        'Reactive',
        'Negative',
    ];

    public const CBC_RESULTS = [
        'With Anemia',
        'Without Anemia',
    ];

    public const GDM_RESULTS = [
        'Positive',
        'Negative',
    ];

    /**
     * Prenatal visit schedule (8 visits) by trimester.
     *
     * @return array<string, array{label: string, weeks: string, visits: list<array{key: string, label: string}>}>
     */
    public static function prenatalSchedule(): array
    {
        return [
            'first' => [
                'label' => '1st Trimester',
                'weeks' => '0–12 weeks',
                'visits' => [
                    ['key' => 't1_v1', 'label' => '1st Visit'],
                ],
            ],
            'second' => [
                'label' => '2nd Trimester',
                'weeks' => '13–27 weeks',
                'visits' => [
                    ['key' => 't2_v1', 'label' => '1st Visit'],
                    ['key' => 't2_v2', 'label' => '2nd Visit'],
                ],
            ],
            'third' => [
                'label' => '3rd Trimester',
                'weeks' => '28–40 weeks',
                'visits' => [
                    ['key' => 't3_v1', 'label' => '1st Visit'],
                    ['key' => 't3_v2', 'label' => '2nd Visit'],
                    ['key' => 't3_v3', 'label' => '3rd Visit'],
                    ['key' => 't3_v4', 'label' => '4th Visit'],
                    ['key' => 't3_v5', 'label' => '5th Visit'],
                ],
            ],
        ];
    }

    /**
     * @return array{deworming: array{label: string, max: int}, ifa: array{label: string, max: int, visits: list<array{key: string, label: string}>}, mms: array{label: string, max: int, visits: list<array{key: string, label: string}>}, calcium: array{label: string, max: int, high_risk_only: bool, visits: list<array{key: string, label: string}>}}
     */
    public static function supplementationSchedule(): array
    {
        $sixVisits = [
            ['key' => 'v1', 'label' => 'Visit 1 (1st Trimester)'],
            ['key' => 'v2', 'label' => 'Visit 2 (2nd Trimester)'],
            ['key' => 'v3', 'label' => 'Visit 3 (2nd Trimester)'],
            ['key' => 'v4', 'label' => 'Visit 4 (3rd Trimester)'],
            ['key' => 'v5', 'label' => 'Visit 5 (3rd Trimester)'],
            ['key' => 'v6', 'label' => 'Visit 6 (3rd Trimester)'],
        ];

        return [
            'deworming' => [
                'label' => 'Deworming Tablet',
                'max' => 1,
            ],
            'ifa' => [
                'label' => 'Iron with Folic Acid Supplementation',
                'max' => 6,
                'visits' => $sixVisits,
            ],
            'mms' => [
                'label' => 'Multiple Micronutrient Supplementation',
                'max' => 6,
                'visits' => $sixVisits,
            ],
            'calcium' => [
                'label' => 'Calcium Carbonate Supplementation',
                'max' => 3,
                'high_risk_only' => true,
                'visits' => [
                    ['key' => 'v1', 'label' => 'Visit 1'],
                    ['key' => 'v2', 'label' => 'Visit 2'],
                    ['key' => 'v3', 'label' => 'Visit 3'],
                ],
            ],
        ];
    }

    /**
     * @return list<array{key: string, label: string, hint: string}>
     */
    public static function postnatalContacts(): array
    {
        return [
            ['key' => 'c1', 'label' => 'Contact 1', 'hint' => 'Within 24 hrs after delivery'],
            ['key' => 'c2', 'label' => 'Contact 2', 'hint' => 'On day 3'],
            ['key' => 'c3', 'label' => 'Contact 3', 'hint' => 'Between 7–14 days'],
            ['key' => 'c4', 'label' => 'Contact 4', 'hint' => '6 weeks after birth'],
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function postpartumSupplementationVisits(): array
    {
        return [
            ['key' => 'v1', 'label' => 'Visit 1'],
            ['key' => 'v2', 'label' => 'Visit 2'],
            ['key' => 'v3', 'label' => 'Visit 3'],
        ];
    }

    /**
     * DB-first identity via HouseholdMemberResolver; DemoCatalog read fallback.
     * Presentation shape preserved for frozen Maternal Care UI.
     *
     * @return array{household: array<string, mixed>|null, member: array<string, mixed>|null, householdNo: string, memberId: string}
     */
    public static function resolveMember(string $householdNo, string $memberId): array
    {
        $ctx = app(HealthMemberIdentity::class)->resolve($householdNo, $memberId);

        return [
            'household' => $ctx['household'],
            'member' => $ctx['member'],
            'householdNo' => $ctx['householdNo'],
            'memberId' => $ctx['memberId'],
        ];
    }

    /**
     * @return array<string, mixed>|null Active pregnancy record or null when none.
     */
    public static function activePregnancy(string $householdNo, string $memberId): ?array
    {
        $state = self::memberState($householdNo, $memberId);
        $active = $state['active'] ?? null;

        return is_array($active) ? self::normalizePregnancy($active) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function history(string $householdNo, string $memberId): array
    {
        $state = self::memberState($householdNo, $memberId);
        $history = is_array($state['history'] ?? null) ? $state['history'] : [];

        return array_values(array_map(
            static fn (array $row): array => self::normalizePregnancy($row),
            array_filter($history, static fn ($row): bool => is_array($row))
        ));
    }

    public static function hasRecord(string $householdNo, string $memberId): bool
    {
        return self::activePregnancy($householdNo, $memberId) !== null
            || self::history($householdNo, $memberId) !== [];
    }

    /**
     * Register a new active pregnancy (session preview).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function register(string $householdNo, string $memberId, array $payload): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $state = self::memberState($hh, $mb);

        $lmp = self::sanitizeDate($payload['lmp'] ?? null);
        $pregnancy = self::emptyPregnancy();
        $pregnancy['id'] = self::nextPregnancyId($state);
        $pregnancy['number'] = count($state['history'] ?? []) + 1;
        $pregnancy['lmp'] = $lmp;
        $pregnancy['gravida'] = self::sanitizeInt($payload['gravida'] ?? null);
        $pregnancy['parity'] = self::sanitizeInt($payload['parity'] ?? null);
        $pregnancy['edd'] = self::sanitizeDate($payload['edd'] ?? null) ?: self::estimateEdd($lmp);
        $pregnancy['weight'] = self::sanitizeDecimal($payload['weight'] ?? null);
        $pregnancy['height'] = self::sanitizeDecimal($payload['height'] ?? null);
        $pregnancy['bmi'] = self::sanitizeDecimal($payload['bmi'] ?? null) ?: self::estimateBmi(
            $pregnancy['weight'],
            $pregnancy['height']
        );
        $pregnancy['blood_pressure'] = self::sanitizeText($payload['blood_pressure'] ?? null);
        $pregnancy['registered_at'] = now()->toDateString();
        $pregnancy['status'] = 'active';

        $state['active'] = $pregnancy;
        self::putMemberState($hh, $mb, $state);

        return self::normalizePregnancy($pregnancy);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function updateSection(
        string $householdNo,
        string $memberId,
        string $section,
        array $payload
    ): ?array {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $state = self::memberState($hh, $mb);
        $active = is_array($state['active'] ?? null) ? $state['active'] : null;
        if ($active === null) {
            return null;
        }

        $section = strtolower(trim($section));
        switch ($section) {
            case 'prenatal':
                $active['prenatal'] = self::mergePrenatal($active['prenatal'] ?? [], $payload);
                break;
            case 'immunizations':
                $active['immunizations'] = self::mergeImmunizations($active['immunizations'] ?? [], $payload);
                break;
            case 'supplementations':
                $active['supplementations'] = self::mergeSupplementations(
                    $active['supplementations'] ?? [],
                    $payload
                );
                break;
            case 'laboratory':
                $active['laboratory'] = self::mergeLaboratory($active['laboratory'] ?? [], $payload);
                break;
            case 'delivery':
                $active['delivery'] = self::mergeDelivery($active['delivery'] ?? [], $payload);
                break;
            case 'postnatal':
                $active['postnatal'] = self::mergePostnatal($active['postnatal'] ?? [], $payload);
                break;
            case 'trans-out':
                $active['trans_out'] = self::mergeTransOut($active['trans_out'] ?? [], $payload);
                $active['status'] = 'transferred_out';
                $history = is_array($state['history'] ?? null) ? $state['history'] : [];
                $history[] = $active;
                $state['history'] = $history;
                $state['active'] = null;
                self::putMemberState($hh, $mb, $state);

                return self::normalizePregnancy($active);
            default:
                return null;
        }

        $state['active'] = $active;
        self::putMemberState($hh, $mb, $state);

        return self::normalizePregnancy($active);
    }

    /**
     * @return array{gestational_age_label: string, trimester_label: string, trimester_key: string}
     */
    public static function gestationalInfo(?string $lmp): array
    {
        if ($lmp === null || $lmp === '') {
            return [
                'gestational_age_label' => '—',
                'trimester_label' => '—',
                'trimester_key' => '',
            ];
        }

        try {
            $start = \Carbon\Carbon::parse($lmp)->startOfDay();
            $today = \Carbon\Carbon::today();
            if ($start->greaterThan($today)) {
                return [
                    'gestational_age_label' => '0 Weeks, 0 Days',
                    'trimester_label' => '1st Trimester',
                    'trimester_key' => 'first',
                ];
            }

            $days = (int) $start->diffInDays($today);
            $weeks = intdiv($days, 7);
            $remDays = $days % 7;
            $trimesterKey = 'first';
            $trimesterLabel = '1st Trimester';
            if ($weeks >= 28) {
                $trimesterKey = 'third';
                $trimesterLabel = '3rd Trimester';
            } elseif ($weeks >= 13) {
                $trimesterKey = 'second';
                $trimesterLabel = '2nd Trimester';
            }

            return [
                'gestational_age_label' => $weeks.' Weeks, '.$remDays.' Days',
                'trimester_label' => $trimesterLabel,
                'trimester_key' => $trimesterKey,
            ];
        } catch (\Throwable) {
            return [
                'gestational_age_label' => '—',
                'trimester_label' => '—',
                'trimester_key' => '',
            ];
        }
    }

    public static function formatDate(?string $iso): string
    {
        if ($iso === null || trim($iso) === '') {
            return '—';
        }

        try {
            return \Carbon\Carbon::parse($iso)->format('F j, Y');
        } catch (\Throwable) {
            return $iso;
        }
    }

    public static function countFilledDates(array $dates): int
    {
        $count = 0;
        foreach ($dates as $value) {
            if (is_string($value) && trim($value) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    public static function prenatalVisitCount(array $pregnancy): int
    {
        $prenatal = is_array($pregnancy['prenatal'] ?? null) ? $pregnancy['prenatal'] : [];
        $count = 0;
        foreach (self::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $row = is_array($prenatal[$visit['key']] ?? null) ? $prenatal[$visit['key']] : [];
                if (trim((string) ($row['date'] ?? '')) !== '') {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    public static function immunizationCount(array $pregnancy): int
    {
        $imm = is_array($pregnancy['immunizations'] ?? null) ? $pregnancy['immunizations'] : [];
        $dates = [];
        foreach (['td1', 'td2', 'td3', 'td4', 'td5'] as $key) {
            $dates[] = (string) ($imm[$key] ?? '');
        }

        return self::countFilledDates($dates);
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     * @return array{deworming: int, ifa: int, mms: int, calcium: int}
     */
    public static function supplementationCounts(array $pregnancy): array
    {
        $supp = is_array($pregnancy['supplementations'] ?? null) ? $pregnancy['supplementations'] : [];
        $deworming = trim((string) ($supp['deworming_date'] ?? '')) !== '' ? 1 : 0;

        $countVisits = static function (array $rows): int {
            $n = 0;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (trim((string) ($row['date'] ?? '')) !== '') {
                    $n++;
                }
            }

            return $n;
        };

        return [
            'deworming' => $deworming,
            'ifa' => $countVisits(is_array($supp['ifa'] ?? null) ? $supp['ifa'] : []),
            'mms' => $countVisits(is_array($supp['mms'] ?? null) ? $supp['mms'] : []),
            'calcium' => $countVisits(is_array($supp['calcium'] ?? null) ? $supp['calcium'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    public static function postnatalContactCount(array $pregnancy): int
    {
        $post = is_array($pregnancy['postnatal'] ?? null) ? $pregnancy['postnatal'] : [];
        $contacts = is_array($post['contacts'] ?? null) ? $post['contacts'] : [];
        $dates = [];
        foreach (self::postnatalContacts() as $contact) {
            $dates[] = (string) ($contacts[$contact['key']] ?? '');
        }

        return self::countFilledDates($dates);
    }

    /**
     * @param  array<string, mixed>  $pregnancy
     */
    public static function postpartumSuppCount(array $pregnancy): int
    {
        $post = is_array($pregnancy['postnatal'] ?? null) ? $pregnancy['postnatal'] : [];
        $visits = is_array($post['supplementation'] ?? null) ? $post['supplementation'] : [];
        $n = 0;
        foreach (self::postpartumSupplementationVisits() as $visit) {
            $row = is_array($visits[$visit['key']] ?? null) ? $visits[$visit['key']] : [];
            if (trim((string) ($row['date'] ?? '')) !== '') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private static function memberState(string $householdNo, string $memberId): array
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $all = session(self::SESSION_KEY, []);
        if (! is_array($all)) {
            $all = [];
        }
        $state = $all[$hh][$mb] ?? null;

        return is_array($state) ? $state : ['active' => null, 'history' => []];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function putMemberState(string $householdNo, string $memberId, array $state): void
    {
        $hh = DemoCatalog::normalizeHouseholdNo($householdNo);
        $mb = DemoCatalog::normalizeMemberId($memberId);
        $all = session(self::SESSION_KEY, []);
        if (! is_array($all)) {
            $all = [];
        }
        if (! isset($all[$hh]) || ! is_array($all[$hh])) {
            $all[$hh] = [];
        }
        $all[$hh][$mb] = $state;
        session([self::SESSION_KEY => $all]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private static function nextPregnancyId(array $state): string
    {
        $max = 0;
        foreach (($state['history'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (preg_match('/MC-(\d+)/', (string) ($row['id'] ?? ''), $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        if (is_array($state['active'] ?? null)
            && preg_match('/MC-(\d+)/', (string) ($state['active']['id'] ?? ''), $m)
        ) {
            $max = max($max, (int) $m[1]);
        }

        return 'MC-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyPregnancy(): array
    {
        $prenatal = [];
        foreach (self::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $prenatal[$visit['key']] = [
                    'date' => '',
                    'height' => '',
                    'weight' => '',
                    'bmi' => '',
                    'bp' => '',
                ];
            }
        }

        $ifa = [];
        $mms = [];
        foreach (self::supplementationSchedule()['ifa']['visits'] as $visit) {
            $ifa[$visit['key']] = ['date' => '', 'tablets' => ''];
            $mms[$visit['key']] = ['date' => '', 'tablets' => ''];
        }
        $calcium = [];
        foreach (self::supplementationSchedule()['calcium']['visits'] as $visit) {
            $calcium[$visit['key']] = ['date' => '', 'tablets' => ''];
        }

        $contacts = [];
        foreach (self::postnatalContacts() as $contact) {
            $contacts[$contact['key']] = '';
        }
        $ppSupp = [];
        foreach (self::postpartumSupplementationVisits() as $visit) {
            $ppSupp[$visit['key']] = ['date' => '', 'tablets' => ''];
        }

        return [
            'id' => '',
            'number' => 1,
            'status' => 'active',
            'lmp' => '',
            'edd' => '',
            'gravida' => '',
            'parity' => '',
            'weight' => '',
            'height' => '',
            'bmi' => '',
            'blood_pressure' => '',
            'registered_at' => '',
            'prenatal' => $prenatal,
            'immunizations' => [
                'td1' => '',
                'td2' => '',
                'td3' => '',
                'td4' => '',
                'td5' => '',
            ],
            'supplementations' => [
                'deworming_date' => '',
                'ifa' => $ifa,
                'mms' => $mms,
                'calcium' => $calcium,
            ],
            'laboratory' => [
                'hepatitis_b' => ['date' => '', 'result' => ''],
                'cbc' => ['date' => '', 'result' => ''],
                'gdm' => ['date' => '', 'result' => ''],
            ],
            'delivery' => [
                'outcome' => '',
                'fetal_death_date' => '',
                'abortion_date' => '',
                'delivery_type' => '',
                'birth_weight' => '',
                'status' => '',
                'datetime' => '',
                'date_terminated' => '',
                'birth_attendant' => '',
                'birth_attendant_other' => '',
                'place' => '',
                'facility_name' => '',
                'bemonc_cemonc' => '',
            ],
            'postnatal' => [
                'contacts' => $contacts,
                'supplementation' => $ppSupp,
            ],
            'trans_out' => [
                'to_facility' => '',
                'occurred_at_stage' => '',
                'reason' => '',
                'date_transferred_out' => '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizePregnancy(array $row): array
    {
        $base = self::emptyPregnancy();
        $merged = array_replace_recursive($base, $row);
        $gestation = self::gestationalInfo((string) ($merged['lmp'] ?? ''));
        $merged['gestational_age_label'] = $gestation['gestational_age_label'];
        $merged['trimester_label'] = $gestation['trimester_label'];
        $merged['trimester_key'] = $gestation['trimester_key'];
        $merged['lmp_label'] = self::formatDate((string) ($merged['lmp'] ?? ''));
        $merged['edd_label'] = self::formatDate((string) ($merged['edd'] ?? ''));
        $merged['gravida_parity_label'] = trim(
            (string) ($merged['gravida'] ?? '').'–'.(string) ($merged['parity'] ?? ''),
            '–'
        );
        if ($merged['gravida_parity_label'] === '') {
            $merged['gravida_parity_label'] = '—';
        } elseif (! str_contains($merged['gravida_parity_label'], '–')) {
            $g = (string) ($merged['gravida'] ?? '—');
            $p = (string) ($merged['parity'] ?? '—');
            $merged['gravida_parity_label'] = ($g !== '' ? $g : '—').'–'.($p !== '' ? $p : '—');
        }

        return $merged;
    }

    private static function estimateEdd(?string $lmp): string
    {
        if ($lmp === null || $lmp === '') {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($lmp)->addDays(280)->toDateString();
        } catch (\Throwable) {
            return '';
        }
    }

    private static function estimateBmi(?string $weight, ?string $height): string
    {
        $w = is_numeric($weight) ? (float) $weight : 0.0;
        $hCm = is_numeric($height) ? (float) $height : 0.0;
        if ($w <= 0 || $hCm <= 0) {
            return '';
        }
        $hM = $hCm / 100;
        $bmi = $w / ($hM * $hM);

        return number_format($bmi, 1, '.', '');
    }

    private static function sanitizeDate(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return '';
        }

        return $raw;
    }

    private static function sanitizeDateTime(mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $raw)) {
            return substr($raw, 0, 16);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw.'T00:00';
        }

        return '';
    }

    private static function sanitizeText(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function sanitizeInt(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return (string) (int) $value;
        }

        return '';
    }

    private static function sanitizeDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergePrenatal(array $current, array $payload): array
    {
        $visits = is_array($payload['visits'] ?? null) ? $payload['visits'] : $payload;
        foreach (self::prenatalSchedule() as $trimester) {
            foreach ($trimester['visits'] as $visit) {
                $key = $visit['key'];
                $row = is_array($visits[$key] ?? null) ? $visits[$key] : [];
                $current[$key] = [
                    'date' => self::sanitizeDate($row['date'] ?? null),
                    'height' => self::sanitizeDecimal($row['height'] ?? null),
                    'weight' => self::sanitizeDecimal($row['weight'] ?? null),
                    'bmi' => self::sanitizeDecimal($row['bmi'] ?? null),
                    'bp' => self::sanitizeText($row['bp'] ?? null),
                ];
            }
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeImmunizations(array $current, array $payload): array
    {
        foreach (['td1', 'td2', 'td3', 'td4', 'td5'] as $key) {
            $current[$key] = self::sanitizeDate($payload[$key] ?? null);
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeSupplementations(array $current, array $payload): array
    {
        $current['deworming_date'] = self::sanitizeDate($payload['deworming_date'] ?? null);

        foreach (['ifa', 'mms', 'calcium'] as $group) {
            $rows = is_array($payload[$group] ?? null) ? $payload[$group] : [];
            $schedule = self::supplementationSchedule()[$group]['visits'];
            $merged = is_array($current[$group] ?? null) ? $current[$group] : [];
            foreach ($schedule as $visit) {
                $key = $visit['key'];
                $row = is_array($rows[$key] ?? null) ? $rows[$key] : [];
                $merged[$key] = [
                    'date' => self::sanitizeDate($row['date'] ?? null),
                    'tablets' => self::sanitizeInt($row['tablets'] ?? null),
                ];
            }
            $current[$group] = $merged;
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeLaboratory(array $current, array $payload): array
    {
        $hep = is_array($payload['hepatitis_b'] ?? null) ? $payload['hepatitis_b'] : [];
        $cbc = is_array($payload['cbc'] ?? null) ? $payload['cbc'] : [];
        $gdm = is_array($payload['gdm'] ?? null) ? $payload['gdm'] : [];

        $hepResult = self::sanitizeText($hep['result'] ?? null);
        $cbcResult = self::sanitizeText($cbc['result'] ?? null);
        $gdmResult = self::sanitizeText($gdm['result'] ?? null);

        $current['hepatitis_b'] = [
            'date' => self::sanitizeDate($hep['date'] ?? null),
            'result' => in_array($hepResult, self::HEPATITIS_B_RESULTS, true) ? $hepResult : '',
        ];
        $current['cbc'] = [
            'date' => self::sanitizeDate($cbc['date'] ?? null),
            'result' => in_array($cbcResult, self::CBC_RESULTS, true) ? $cbcResult : '',
        ];
        $current['gdm'] = [
            'date' => self::sanitizeDate($gdm['date'] ?? null),
            'result' => in_array($gdmResult, self::GDM_RESULTS, true) ? $gdmResult : '',
        ];

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeDelivery(array $current, array $payload): array
    {
        $outcome = self::sanitizeText($payload['outcome'] ?? null);
        if (! array_key_exists($outcome, self::OUTCOMES)) {
            $outcome = '';
        }

        $deliveryType = self::sanitizeText($payload['delivery_type'] ?? null);
        if (! array_key_exists($deliveryType, self::DELIVERY_TYPES)) {
            $deliveryType = '';
        }

        $attendant = self::sanitizeText($payload['birth_attendant'] ?? null);
        if (! array_key_exists($attendant, self::BIRTH_ATTENDANTS)) {
            $attendant = '';
        }
        $attendantOther = $attendant === 'Others'
            ? self::sanitizeText($payload['birth_attendant_other'] ?? null)
            : '';

        $place = self::sanitizeText($payload['place'] ?? null);
        if (! array_key_exists($place, self::PLACES_OF_DELIVERY)) {
            $place = '';
        }

        $bemonc = self::sanitizeText($payload['bemonc_cemonc'] ?? null);
        if (! in_array($bemonc, ['Yes', 'No'], true)) {
            $bemonc = '';
        }

        $current['outcome'] = $outcome;
        $current['fetal_death_date'] = $outcome === 'FD'
            ? self::sanitizeDate($payload['fetal_death_date'] ?? null)
            : '';
        $current['abortion_date'] = $outcome === 'AB'
            ? self::sanitizeDate($payload['abortion_date'] ?? null)
            : '';
        $current['delivery_type'] = in_array($outcome, ['FD', 'AB'], true) ? '' : $deliveryType;
        $current['birth_weight'] = in_array($outcome, ['FD', 'AB'], true)
            ? ''
            : self::sanitizeDecimal($payload['birth_weight'] ?? null);
        $current['status'] = in_array($outcome, ['FD', 'AB'], true)
            ? ''
            : self::sanitizeText($payload['status'] ?? null);
        $current['datetime'] = in_array($outcome, ['FD', 'AB'], true)
            ? ''
            : self::sanitizeDateTime($payload['datetime'] ?? null);
        $current['date_terminated'] = in_array($outcome, ['FD', 'AB'], true)
            ? ''
            : self::sanitizeDate($payload['date_terminated'] ?? null);
        $current['birth_attendant'] = in_array($outcome, ['FD', 'AB'], true) ? '' : $attendant;
        $current['birth_attendant_other'] = in_array($outcome, ['FD', 'AB'], true) ? '' : $attendantOther;
        $current['place'] = in_array($outcome, ['FD', 'AB'], true) ? '' : $place;
        $current['facility_name'] = in_array($outcome, ['FD', 'AB'], true)
            ? ''
            : self::sanitizeText($payload['facility_name'] ?? null);
        $current['bemonc_cemonc'] = in_array($outcome, ['FD', 'AB'], true) ? '' : $bemonc;

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergePostnatal(array $current, array $payload): array
    {
        $contactsIn = is_array($payload['contacts'] ?? null) ? $payload['contacts'] : [];
        $contacts = is_array($current['contacts'] ?? null) ? $current['contacts'] : [];
        foreach (self::postnatalContacts() as $contact) {
            $contacts[$contact['key']] = self::sanitizeDate($contactsIn[$contact['key']] ?? null);
        }

        $suppIn = is_array($payload['supplementation'] ?? null) ? $payload['supplementation'] : [];
        $supp = is_array($current['supplementation'] ?? null) ? $current['supplementation'] : [];
        foreach (self::postpartumSupplementationVisits() as $visit) {
            $row = is_array($suppIn[$visit['key']] ?? null) ? $suppIn[$visit['key']] : [];
            $supp[$visit['key']] = [
                'date' => self::sanitizeDate($row['date'] ?? null),
                'tablets' => self::sanitizeInt($row['tablets'] ?? null),
            ];
        }

        $current['contacts'] = $contacts;
        $current['supplementation'] = $supp;

        return $current;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function mergeTransOut(array $current, array $payload): array
    {
        return [
            'to_facility' => self::sanitizeText($payload['to_facility'] ?? null),
            'occurred_at_stage' => self::sanitizeText($payload['occurred_at_stage'] ?? null),
            'reason' => self::sanitizeText($payload['reason'] ?? null),
            'date_transferred_out' => self::sanitizeDate($payload['date_transferred_out'] ?? null),
        ];
    }
}
