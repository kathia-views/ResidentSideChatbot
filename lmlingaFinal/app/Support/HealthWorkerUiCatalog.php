<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Adapts DB staff users (+ demo catalog fallback) into the frozen Health Worker UI shape.
 *
 * Route IDs:
 * - numeric string → database users.id
 * - hw-{n} → demo catalog only (transition fallback)
 */
final class HealthWorkerUiCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $demo = self::demoWorkers();
        $fromDb = self::databaseWorkers();

        if ($fromDb === []) {
            return $demo;
        }

        // Prefer real accounts; keep demo rows that do not collide on email with DB users.
        $dbEmails = [];
        foreach ($fromDb as $row) {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email !== '') {
                $dbEmails[$email] = true;
            }
        }

        $demoRemainder = array_values(array_filter(
            $demo,
            static function (array $row) use ($dbEmails): bool {
                $email = strtolower(trim((string) ($row['email'] ?? '')));

                return $email === '' || ! isset($dbEmails[$email]);
            }
        ));

        return array_merge($fromDb, $demoRemainder);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        $id = trim($id);

        if (ctype_digit($id)) {
            $user = self::findUserById((int) $id);

            return $user !== null ? self::presentUser($user) : null;
        }

        return collect(self::demoWorkers())->firstWhere('id', $id);
    }

    public static function findUserById(int $id): ?User
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        return User::query()->with('currentAppointment')->find($id);
    }

    /**
     * Resolve a mutable database user from a route id.
     * Demo hw-* ids are not mutable via DB-03.
     */
    public static function findMutableUser(string $id): ?User
    {
        $id = trim($id);

        if (! ctype_digit($id)) {
            return null;
        }

        return self::findUserById((int) $id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentUser(User $user): array
    {
        $appointment = $user->relationLoaded('currentAppointment')
            ? $user->currentAppointment
            : $user->currentAppointment()->first();

        $roleMachine = StaffRole::normalize($appointment?->role);
        $roleLabel = StaffRole::label($roleMachine);
        if ($roleLabel === '' && $appointment?->role) {
            $roleLabel = (string) $appointment->role;
        }

        $dob = $user->date_of_birth;
        $dobString = $dob !== null ? $dob->format('Y-m-d') : '';

        $appointed = $appointment?->date_appointed;
        $ended = $appointment?->end_of_appointment;

        return [
            'id' => (string) $user->id,
            'name' => $user->composeDisplayName(),
            'role' => $roleLabel !== '' ? $roleLabel : '',
            // Prefer appointment zone only — do not invent residential purok as employment zone.
            'zone' => (string) ($appointment?->assigned_zone ?? ''),
            'status' => StaffAccountStatus::normalize($user->status) ?? StaffAccountStatus::ACTIVE,
            'photo' => $user->photo_path,
            'first_name' => (string) ($user->first_name ?? ''),
            'last_name' => (string) ($user->last_name ?? ''),
            'middle_name' => (string) ($user->middle_name ?? ''),
            'suffix' => (string) ($user->suffix ?? ''),
            'sex' => (string) ($user->sex ?? ''),
            'date_of_birth' => $dobString,
            'civil_status' => (string) ($user->civil_status ?? ''),
            'nationality' => (string) ($user->nationality ?? ''),
            'mobile' => (string) ($user->mobile_number ?? ''),
            'email' => (string) ($user->email ?? ''),
            'house_no' => (string) ($user->house_no ?? ''),
            'street' => (string) ($user->street ?? ''),
            'purok_zone' => (string) ($user->purok_zone ?? ''),
            'barangay' => (string) ($user->barangay ?? ''),
            'municipality' => (string) ($user->municipality_city ?? ''),
            'province' => (string) ($user->province ?? ''),
            'zip_code' => (string) ($user->zip_code ?? ''),
            'assigned_barangay' => (string) ($appointment?->assigned_barangay ?? ''),
            'assigned_zone' => (string) ($appointment?->assigned_zone ?? ''),
            'date_appointed' => $appointed !== null ? $appointed->format('Y-m-d') : '',
            'end_of_appointment' => $ended !== null ? $ended->format('Y-m-d') : '',
            'username' => (string) ($user->username ?? ''),
            'source' => 'database',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function databaseWorkers(): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('worker_appointments')) {
            return [];
        }

        return User::query()
            ->with('currentAppointment')
            ->orderBy('id')
            ->get()
            ->map(static fn (User $user): array => self::presentUser($user))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function demoWorkers(): array
    {
        /** @var list<array<string, mixed>> $catalog */
        $catalog = require resource_path('demo/health-workers.php');

        return array_map(static function (array $row): array {
            $row['source'] = 'demo';

            return $row;
        }, $catalog);
    }
}
