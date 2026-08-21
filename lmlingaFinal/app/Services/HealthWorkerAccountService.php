<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkerAppointment;
use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin Health Worker account persistence against users + worker_appointments.
 *
 * Account (auth/admin) and profile (personal/address/employment details) are
 * separate concerns. Slim Create Account persists only values actually collected
 * on the create form plus a role-only current appointment — never demo/fixture
 * demographics and never invented employment placeholders.
 *
 * DB-03 EDIT: updates users profile/account fields and updates the current
 * worker_appointments row in place. Does not call User::assignCurrentAppointment().
 * If no current appointment exists, creates the first current row from Edit wizard
 * employment fields (authoritative UI input).
 */
final class HealthWorkerAccountService
{
    /**
     * Persist a new Health Worker authentication account from the slim create form.
     *
     * @param  array<string, mixed>  $data  Validated StoreHealthWorkerRequest payload
     */
    public function createFromSlimForm(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $status = StaffAccountStatus::normalize($data['status'] ?? null);
            if ($status === null) {
                throw ValidationException::withMessages([
                    'status' => 'Invalid account status.',
                ]);
            }

            $role = StaffRole::normalize($data['role'] ?? null);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'role' => 'Invalid staff role.',
                ]);
            }

            $user = new User;
            $user->fill([
                'first_name' => trim((string) $data['first_name']),
                'middle_name' => filled($data['middle_name'] ?? null)
                    ? trim((string) $data['middle_name'])
                    : null,
                'last_name' => trim((string) $data['last_name']),
                'email' => strtolower(trim((string) $data['email'])),
                'mobile_number' => trim((string) $data['mobile']),
                'password' => $data['password'],
                'status' => $status,
                'must_change_password' => true,
                'created_by' => $this->actingAdminId(),
            ]);

            // Explicit incomplete profile — do not invent demographics/address/photo.
            $user->suffix = null;
            $user->sex = null;
            $user->date_of_birth = null;
            $user->civil_status = null;
            $user->nationality = null;
            $user->house_no = null;
            $user->street = null;
            $user->purok_zone = null;
            $user->barangay = null;
            $user->municipality_city = null;
            $user->province = null;
            $user->zip_code = null;
            $user->photo_path = null;
            $user->username = null;

            $user->save();

            // Role only — employment completed later via Edit Account Details.
            $user->appointments()->create([
                'role' => $role,
                'assigned_barangay' => null,
                'assigned_zone' => null,
                'date_appointed' => null,
                'end_of_appointment' => null,
                'is_current' => true,
            ]);

            return $user->fresh(['currentAppointment']);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated UpdateHealthWorkerRequest payload
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $status = StaffAccountStatus::normalize($data['hw_status'] ?? null);
            if ($status === null) {
                throw ValidationException::withMessages([
                    'hw_status' => 'Invalid account status.',
                ]);
            }

            $role = StaffRole::normalize($data['hw_role'] ?? null);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'hw_role' => 'Invalid staff role.',
                ]);
            }

            $profile = [
                'first_name' => $data['hw_first_name'],
                'middle_name' => $data['hw_middle_name'] ?? null,
                'last_name' => $data['hw_last_name'],
                'suffix' => $data['hw_suffix'] ?? null,
                'sex' => $data['sex'] ?? null,
                'date_of_birth' => $data['hw_dob'] ?? null,
                'civil_status' => $data['hw_civil_status'] ?? null,
                'nationality' => $data['hw_nationality'] ?? null,
                'mobile_number' => $data['hw_mobile'] ?? null,
                'email' => $data['hw_email'],
                'house_no' => $data['hw_house_no'] ?? null,
                'street' => $data['hw_street'] ?? null,
                'purok_zone' => $data['hw_purok_zone'] ?? null,
                'barangay' => $data['hw_barangay'] ?? null,
                'municipality_city' => $data['hw_municipality'] ?? null,
                'province' => $data['hw_province'] ?? null,
                'zip_code' => $data['hw_zip'] ?? null,
                'username' => $data['hw_username'],
                'status' => $status,
            ];

            $password = $data['hw_password'] ?? null;
            if (is_string($password) && $password !== '') {
                $profile['password'] = $password;
                $profile['must_change_password'] = true;
            }

            $user->fill($profile);
            $user->save();

            $this->syncCurrentAppointmentInPlace($user, [
                'role' => $role,
                'assigned_barangay' => (string) $data['hw_assigned_barangay'],
                'assigned_zone' => (string) $data['hw_assigned_zone'],
                'date_appointed' => $data['hw_date_appointed'],
                'end_of_appointment' => $data['hw_end_appointment'] ?? null,
            ]);

            return $user->fresh(['currentAppointment']);
        });
    }

    /**
     * @param  array{
     *     role: string,
     *     assigned_barangay: string,
     *     assigned_zone: string,
     *     date_appointed: string,
     *     end_of_appointment?: string|null
     * }  $employment
     */
    private function syncCurrentAppointmentInPlace(User $user, array $employment): WorkerAppointment
    {
        /** @var WorkerAppointment|null $current */
        $current = $user->currentAppointment()->first();

        $attributes = [
            'role' => $employment['role'],
            'assigned_barangay' => $employment['assigned_barangay'],
            'assigned_zone' => $employment['assigned_zone'],
            'date_appointed' => $employment['date_appointed'],
            'end_of_appointment' => $employment['end_of_appointment'] ?: null,
            'is_current' => true,
        ];

        if ($current !== null) {
            $current->fill($attributes);
            $current->save();

            return $current->fresh();
        }

        // Legacy / pre-appointment transition: first current row from Edit wizard fields.
        /** @var WorkerAppointment $created */
        $created = $user->appointments()->create($attributes);

        $user->unsetRelation('currentAppointment');
        $user->unsetRelation('appointments');

        return $created;
    }

    public function actingAdminId(): ?int
    {
        $admin = Auth::user();

        return $admin instanceof User ? $admin->id : null;
    }
}
