<?php

namespace Tests\Unit;

use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class RecordRequestOtpModelTest extends TestCase
{
    public function test_model_maps_to_record_request_otps_with_otp_id_primary_key(): void
    {
        $model = new RecordRequestOtp;

        $this->assertSame('record_request_otps', $model->getTable());
        $this->assertSame('otp_id', $model->getKeyName());
        $this->assertTrue($model->usesTimestamps());
    }

    public function test_security_fields_are_not_mass_assignable(): void
    {
        $model = new RecordRequestOtp;

        $this->assertFalse($model->isFillable('otp_id'));
        $this->assertFalse($model->isFillable('request_id'));
        $this->assertFalse($model->isFillable('code_hash'));
        $this->assertFalse($model->isFillable('destination_fingerprint'));
        $this->assertFalse($model->isFillable('expires_at'));
        $this->assertFalse($model->isFillable('attempt_count'));
        $this->assertFalse($model->isFillable('resend_count'));
        $this->assertFalse($model->isFillable('verified_at'));
        $this->assertFalse($model->isFillable('invalidated_at'));
        $this->assertFalse($model->isFillable('account_id'));
        $this->assertFalse($model->isFillable('resident_id'));
        $this->assertFalse($model->isFillable('matched_resident_id'));
    }

    public function test_hash_and_fingerprint_are_hidden(): void
    {
        $model = new RecordRequestOtp;
        $hidden = $model->getHidden();

        $this->assertContains('code_hash', $hidden);
        $this->assertContains('destination_fingerprint', $hidden);
    }

    public function test_record_request_relationships_are_defined(): void
    {
        $belongsTo = (new RecordRequestOtp)->recordRequest();
        $hasMany = (new RecordRequest)->otps();

        $this->assertInstanceOf(BelongsTo::class, $belongsTo);
        $this->assertSame(RecordRequest::class, $belongsTo->getRelated()::class);
        $this->assertSame('request_id', $belongsTo->getForeignKeyName());
        $this->assertSame('request_id', $belongsTo->getOwnerKeyName());

        $this->assertInstanceOf(HasMany::class, $hasMany);
        $this->assertSame(RecordRequestOtp::class, $hasMany->getRelated()::class);
        $this->assertSame('request_id', $hasMany->getForeignKeyName());
        $this->assertSame('request_id', $hasMany->getLocalKeyName());
    }
}
