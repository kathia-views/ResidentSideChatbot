<?php

namespace Tests\Unit;

use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tests\TestCase;

class RecordRequestModelTest extends TestCase
{
    public function test_model_maps_to_record_requests_with_request_id_primary_key(): void
    {
        $model = new RecordRequest;

        $this->assertSame('record_requests', $model->getTable());
        $this->assertSame('request_id', $model->getKeyName());
        $this->assertTrue($model->usesTimestamps());
    }

    public function test_decision_columns_are_not_mass_assignable(): void
    {
        $model = new RecordRequest;

        $this->assertFalse($model->isFillable('matched_resident_id'));
        $this->assertFalse($model->isFillable('status'));
        $this->assertFalse($model->isFillable('decision_reason'));
        $this->assertFalse($model->isFillable('evaluated_at'));
        $this->assertFalse($model->isFillable('approved_at'));
        $this->assertTrue($model->isFillable('account_id'));
        $this->assertTrue($model->isFillable('household_no_submitted'));
    }

    public function test_resident_account_relationships_are_defined(): void
    {
        $belongsTo = (new RecordRequest)->residentAccount();
        $hasMany = (new ResidentAccount)->recordRequests();

        $this->assertInstanceOf(BelongsTo::class, $belongsTo);
        $this->assertSame(ResidentAccount::class, $belongsTo->getRelated()::class);
        $this->assertSame('account_id', $belongsTo->getForeignKeyName());
        $this->assertSame('account_id', $belongsTo->getOwnerKeyName());

        $this->assertInstanceOf(HasMany::class, $hasMany);
        $this->assertSame(RecordRequest::class, $hasMany->getRelated()::class);
        $this->assertSame('account_id', $hasMany->getForeignKeyName());
        $this->assertSame('account_id', $hasMany->getLocalKeyName());

        $otps = (new RecordRequest)->otps();
        $this->assertInstanceOf(HasMany::class, $otps);
        $this->assertSame(RecordRequestOtp::class, $otps->getRelated()::class);
    }
}
