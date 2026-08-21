<?php

namespace App\Models;

use App\Support\StaffRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class WorkerAppointment extends Model
{
    /** @use HasFactory<\Database\Factories\WorkerAppointmentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'role',
        'assigned_barangay',
        'assigned_zone',
        'date_appointed',
        'end_of_appointment',
        'is_current',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_appointed' => 'date',
            'end_of_appointment' => 'date',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WorkerAppointment $appointment): void {
            $role = StaffRole::normalize($appointment->role);
            if ($role === null) {
                throw ValidationException::withMessages([
                    'role' => 'Invalid staff role.',
                ]);
            }
            $appointment->role = $role;

            if ($appointment->is_current) {
                static::query()
                    ->where('user_id', $appointment->user_id)
                    ->when($appointment->exists, fn ($q) => $q->whereKeyNot($appointment->getKey()))
                    ->where('is_current', true)
                    ->update([
                        'is_current' => false,
                        'end_of_appointment' => $appointment->end_of_appointment
                            ?? now()->toDateString(),
                    ]);
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markEnded(\DateTimeInterface|string|null $endedOn = null): void
    {
        $this->forceFill([
            'is_current' => false,
            'end_of_appointment' => $endedOn ?? now()->toDateString(),
        ])->save();
    }
}
