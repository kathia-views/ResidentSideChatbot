<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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
}
