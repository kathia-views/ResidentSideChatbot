<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class Resident extends Model
{
    /** @use HasFactory<\Database\Factories\ResidentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'household_id',
        'member_no',
        'last_name',
        'first_name',
        'middle_name',
        'relation',
        'birthday',
        'sex',
        'relationship_status',
        'occupation',
        'monthly_income',
        'religion',
        'education',
        'fp_user',
        'philhealth',
        'disability',
        'disability_others',
        'medical_history',
        'medical_others',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'disability' => 'array',
            'medical_history' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * @return HasMany<DeathRequest, $this>
     */
    public function deathRequests(): HasMany
    {
        return $this->hasMany(DeathRequest::class);
    }

    /**
     * @return HasMany<ResidentStatus, $this>
     */
    public function residentStatuses(): HasMany
    {
        return $this->hasMany(ResidentStatus::class);
    }

    /**
     * @return HasOne<ChildBirthHistory, $this>
     */
    public function childBirthHistory(): HasOne
    {
        return $this->hasOne(ChildBirthHistory::class);
    }

    /**
     * @return HasMany<DewormingRecord, $this>
     */
    public function dewormingRecords(): HasMany
    {
        return $this->hasMany(DewormingRecord::class);
    }

    /**
     * Chatbot linking only. Live MySQL PK is `resident_id`; sqlite tests use `id`.
     * Other Resident relations keep Laravel's default `id` local key.
     */
    public static function resolvedPrimaryKeyName(): string
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        try {
            if (! Schema::hasTable('residents')) {
                return $resolved = 'id';
            }

            if (Schema::hasColumn('residents', 'id')) {
                return $resolved = 'id';
            }

            if (Schema::hasColumn('residents', 'resident_id')) {
                return $resolved = 'resident_id';
            }
        } catch (\Throwable) {
            return $resolved = 'id';
        }

        return $resolved = 'id';
    }

    /**
     * Optional chatbot login account. Official identity does not require an account.
     *
     * @return HasOne<ResidentAccount, $this>
     */
    public function residentAccount(): HasOne
    {
        return $this->hasOne(ResidentAccount::class, 'resident_id', static::resolvedPrimaryKeyName());
    }
}
