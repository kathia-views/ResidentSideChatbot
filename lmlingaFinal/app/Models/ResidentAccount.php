<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResidentAccount extends Model
{
    protected $table = 'resident_accounts';

    protected $primaryKey = 'account_id';

    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'zone_purok',
        'email',
        'password',
        'resident_id',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Official barangay resident this chatbot account is linked to, if any.
     *
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id', Resident::resolvedPrimaryKeyName());
    }

    /**
     * Chatbot password-reset records for this login account.
     *
     * @return HasMany<ResidentPasswordResetToken, $this>
     */
    public function passwordResets(): HasMany
    {
        return $this->hasMany(ResidentPasswordResetToken::class, 'account_id', 'account_id');
    }

    /**
     * Household record access requests submitted by this chatbot account.
     *
     * @return HasMany<RecordRequest, $this>
     */
    public function recordRequests(): HasMany
    {
        return $this->hasMany(RecordRequest::class, 'account_id', 'account_id');
    }
}