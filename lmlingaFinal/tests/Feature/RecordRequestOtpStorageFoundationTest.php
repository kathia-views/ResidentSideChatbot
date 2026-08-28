<?php

namespace Tests\Feature;

use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\ResidentAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecordRequestOtpStorageFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function accountAttributes(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
            'zone_purok' => '2',
            'email' => 'ana.otp.storage@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    private function seedAwaitingOtpRequest(ResidentAccount $account): RecordRequest
    {
        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = 'HH-001';
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = $account->email;
        $row->submitter_ip = '203.0.113.40';
        $row->matched_resident_id = 17;
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = null;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        return $row->fresh();
    }

    public function test_sqlite_has_record_request_otps_schema_without_plaintext_otp_columns(): void
    {
        $this->assertTrue(Schema::hasTable('record_request_otps'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'otp_id'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'request_id'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'code_hash'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'destination_fingerprint'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'expires_at'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'attempt_count'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'resend_count'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'last_sent_at'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'verified_at'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'invalidated_at'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'created_at'));
        $this->assertTrue(Schema::hasColumn('record_request_otps', 'updated_at'));

        foreach (['code', 'otp', 'plain_code', 'verification_code', 'account_id', 'resident_id'] as $forbidden) {
            $this->assertFalse(Schema::hasColumn('record_request_otps', $forbidden));
        }
    }

    public function test_otp_row_belongs_to_owned_request_without_changing_status_or_links(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $request = $this->seedAwaitingOtpRequest($account);
        $statusBefore = $request->status;
        $matchedBefore = $request->matched_resident_id;
        $residentIdBefore = $account->resident_id;
        $updatedAtBefore = $request->updated_at?->toJSON();

        $otp = new RecordRequestOtp;
        $otp->request_id = $request->request_id;
        $otp->code_hash = 'storage-foundation-placeholder-hash';
        $otp->destination_fingerprint = 'storage-foundation-placeholder-fingerprint';
        $otp->expires_at = now()->addMinutes(5);
        $otp->save();

        $otp = $otp->fresh();
        $request = $request->fresh();
        $account = $account->fresh();

        $this->assertSame(0, $otp->attempt_count);
        $this->assertSame(0, $otp->resend_count);
        $this->assertNull($otp->verified_at);
        $this->assertNull($otp->invalidated_at);
        $this->assertNull($otp->last_sent_at);
        $this->assertNotNull($otp->expires_at);
        $this->assertSame((int) $request->request_id, (int) $otp->request_id);
        $this->assertTrue($otp->recordRequest->is($request));
        $this->assertTrue($request->otps->contains($otp));
        $this->assertSame(1, $request->otps()->count());

        $serialized = $otp->toArray();
        $this->assertArrayNotHasKey('code_hash', $serialized);
        $this->assertArrayNotHasKey('destination_fingerprint', $serialized);

        $this->assertSame($statusBefore, $request->status);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->status);
        $this->assertSame($matchedBefore, $request->matched_resident_id);
        $this->assertSame($updatedAtBefore, $request->updated_at?->toJSON());
        $this->assertNull($request->approved_at);
        $this->assertSame($residentIdBefore, $account->resident_id);
        $this->assertNull($account->resident_id);
        $this->assertDatabaseCount('record_request_otps', 1);
    }

    public function test_otp_row_cannot_be_created_via_mass_assignment(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'ana.otp.mass@example.com',
        ]));
        $request = $this->seedAwaitingOtpRequest($account);

        $this->assertDatabaseCount('record_request_otps', 0);
        $this->assertSame(RecordRequest::STATUS_AWAITING_OTP, $request->status);
        $this->assertNull($account->fresh()->resident_id);

        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        (new RecordRequestOtp)->fill([
            'request_id' => $request->request_id,
            'code_hash' => 'must-not-assign',
            'destination_fingerprint' => 'must-not-assign',
            'expires_at' => now()->addMinutes(5),
            'attempt_count' => 9,
        ]);
    }
}
