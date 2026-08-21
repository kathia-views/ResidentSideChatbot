<?php

namespace App\Models;

use App\Support\StaffAccountStatus;
use App\Support\StaffRole;
use App\Support\UiRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'photo_path',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'sex',
        'date_of_birth',
        'civil_status',
        'nationality',
        'mobile_number',
        'email',
        'house_no',
        'street',
        'purok_zone',
        'barangay',
        'municipality_city',
        'province',
        'zip_code',
        'username',
        'password',
        'status',
        'must_change_password',
        'created_by',
        'email_verified_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->status !== null) {
                $normalized = StaffAccountStatus::normalize($user->status);
                if ($normalized === null) {
                    throw ValidationException::withMessages([
                        'status' => 'Invalid staff account status.',
                    ]);
                }
                $user->status = $normalized;
            }

            if ($user->isDirty(['first_name', 'middle_name', 'last_name', 'suffix']) || $user->name === null || $user->name === '') {
                $user->name = $user->composeDisplayName();
            }
        });
    }

    public function composeDisplayName(): string
    {
        $parts = array_filter([
            trim((string) $this->first_name),
            trim((string) $this->middle_name),
            trim((string) $this->last_name),
        ], static fn (string $part): bool => $part !== '');

        $base = implode(' ', $parts);
        $suffix = trim((string) $this->suffix);
        if ($suffix !== '' && strcasecmp($suffix, 'N/A') !== 0) {
            $base = trim($base.' '.$suffix);
        }

        if ($base !== '') {
            return $base;
        }

        return trim((string) $this->name) !== ''
            ? (string) $this->name
            : (string) ($this->email ?? 'Staff User');
    }

    /**
     * Effective shell role from the current worker appointment (authoritative).
     * Exposed as `role` so UiRole::current() keeps working with Auth::user().
     */
    public function getRoleAttribute(): ?string
    {
        $appointment = $this->relationLoaded('currentAppointment')
            ? $this->currentAppointment
            : $this->currentAppointment()->first();

        return StaffRole::normalize($appointment?->role);
    }

    public function isActive(): bool
    {
        return StaffAccountStatus::normalize($this->status) === StaffAccountStatus::ACTIVE;
    }

    public function deactivate(): void
    {
        $this->forceFill(['status' => StaffAccountStatus::INACTIVE])->save();
    }

    public function activate(): void
    {
        $this->forceFill(['status' => StaffAccountStatus::ACTIVE])->save();
    }

    /**
     * @return HasMany<WorkerAppointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(WorkerAppointment::class);
    }

    /**
     * @return HasOne<WorkerAppointment, $this>
     */
    public function currentAppointment(): HasOne
    {
        return $this->hasOne(WorkerAppointment::class)->where('is_current', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<User, $this>
     */
    public function createdUsers(): HasMany
    {
        return $this->hasMany(User::class, 'created_by');
    }

    /**
     * Establish or replace the current appointment (ends prior current rows).
     *
     * @param  array{
     *     role: string,
     *     assigned_barangay: string,
     *     assigned_zone: string,
     *     date_appointed: string|\DateTimeInterface,
     *     end_of_appointment?: string|\DateTimeInterface|null
     * }  $attributes
     */
    public function assignCurrentAppointment(array $attributes): WorkerAppointment
    {
        $role = StaffRole::normalize($attributes['role'] ?? null);
        if ($role === null) {
            throw ValidationException::withMessages([
                'role' => 'Invalid staff role.',
            ]);
        }

        return $this->getConnection()->transaction(function () use ($attributes, $role): WorkerAppointment {
            $this->appointments()
                ->where('is_current', true)
                ->update([
                    'is_current' => false,
                    'end_of_appointment' => now()->toDateString(),
                ]);

            /** @var WorkerAppointment $appointment */
            $appointment = $this->appointments()->create([
                'role' => $role,
                'assigned_barangay' => (string) $attributes['assigned_barangay'],
                'assigned_zone' => (string) $attributes['assigned_zone'],
                'date_appointed' => $attributes['date_appointed'],
                'end_of_appointment' => $attributes['end_of_appointment'] ?? null,
                'is_current' => true,
            ]);

            $this->unsetRelation('currentAppointment');
            $this->unsetRelation('appointments');

            return $appointment;
        });
    }

    public function syncUiRoleSession(): void
    {
        $role = $this->role;
        if ($role !== null) {
            UiRole::set($role);
        }
    }
}
