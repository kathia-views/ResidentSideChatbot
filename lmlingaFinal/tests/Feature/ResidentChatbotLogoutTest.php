<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\RecordRequest;
use App\Models\RecordRequestOtp;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Services\HouseholdRecordRequestOtpIssuer;
use App\Support\HouseholdRecordRequestMatcher;
use App\Support\UiRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResidentChatbotLogoutTest extends TestCase
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
            'email' => 'ana.logout@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    private function actingAsResident(ResidentAccount $account): void
    {
        $this->withSession(['resident_account_id' => $account->account_id]);
    }

    public function test_logged_in_resident_can_post_logout(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->actingAsResident($account);

        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'));
    }

    public function test_post_logout_removes_resident_account_id(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->actingAsResident($account);

        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'))
            ->assertSessionMissing('resident_account_id');

        $this->assertNull(session('resident_account_id'));
    }

    public function test_post_logout_invalidates_session_and_regenerates_csrf_token(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->withSession(['resident_account_id' => $account->account_id]);

        $tokenBefore = session()->token();
        $sessionIdBefore = session()->getId();

        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'));

        $this->assertNotSame($sessionIdBefore, session()->getId());
        $this->assertNotSame($tokenBefore, session()->token());
        $this->assertNotEmpty(session()->token());
    }

    public function test_post_logout_redirects_to_chatbot_landing(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->actingAsResident($account);

        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'));
    }

    public function test_after_logout_protected_household_routes_redirect_to_login(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->actingAsResident($account);

        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'));

        $this->get(route('chatbot.household.information'))
            ->assertRedirect(route('chatbot.login'));

        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.login'));

        $this->get(route('chatbot.household.verification.sms'))
            ->assertRedirect(route('chatbot.login'));

        $this->get(route('chatbot.household.verification.email'))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_get_logout_does_not_perform_logout(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->actingAsResident($account);

        $this->get('/chatbot/logout')
            ->assertStatus(405);

        $this->assertSame($account->account_id, session('resident_account_id'));
    }

    public function test_logout_does_not_delete_or_unlink_resident_account(): void
    {
        $household = Household::factory()->create();
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);

        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'ana.linked.logout@example.com',
            'resident_id' => $resident->getKey(),
        ]));
        $this->actingAsResident($account);

        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'));

        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $account->account_id,
            'email' => 'ana.linked.logout@example.com',
            'resident_id' => $resident->getKey(),
        ]);

        $this->assertSame($resident->getKey(), $account->fresh()->resident_id);
    }

    public function test_logout_does_not_alter_record_requests_or_otp_rows(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'ana.otp.logout@example.com',
        ]));
        $household = Household::factory()->create(['household_no' => 'HH-LOGOUT-1']);
        $resident = Resident::factory()->create([
            'household_id' => $household->getKey(),
            'first_name' => 'Ana',
            'middle_name' => 'Cruz',
            'last_name' => 'Santos',
        ]);

        $row = new RecordRequest;
        $row->account_id = $account->account_id;
        $row->household_no_submitted = (string) $household->household_no;
        $row->zone_submitted = '2';
        $row->relationship_submitted = 'Household Head';
        $row->first_name_submitted = 'Ana';
        $row->middle_name_submitted = 'Cruz';
        $row->last_name_submitted = 'Santos';
        $row->mobile_number_submitted = '09171234567';
        $row->email_submitted = 'ana.otp.logout@example.com';
        $row->submitter_ip = '127.0.0.1';
        $row->matched_resident_id = $resident->getKey();
        $row->status = RecordRequest::STATUS_AWAITING_OTP;
        $row->decision_reason = HouseholdRecordRequestMatcher::REASON_AWAITING_OTP;
        $row->evaluated_at = now();
        $row->approved_at = null;
        $row->save();

        $otp = new RecordRequestOtp;
        $otp->request_id = $row->request_id;
        $otp->code_hash = Hash::make('123456');
        $otp->destination_fingerprint = app(HouseholdRecordRequestOtpIssuer::class)
            ->fingerprintForDestination(HouseholdRecordRequestOtpIssuer::DEST_MOBILE, '09171234567');
        $otp->expires_at = now()->addMinutes(5);
        $otp->last_sent_at = now();
        $otp->save();

        $requestSnapshot = $row->fresh()->toArray();
        $otpSnapshot = $otp->fresh()->toArray();

        $this->actingAsResident($account);
        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'));

        $this->assertSame(1, RecordRequest::query()->count());
        $this->assertSame(1, RecordRequestOtp::query()->count());
        $this->assertEquals($requestSnapshot, $row->fresh()->toArray());
        $this->assertEquals($otpSnapshot, $otp->fresh()->toArray());
    }

    public function test_staff_admin_session_does_not_become_resident_authenticated_via_logout(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'admin']);

        $this->post(route('chatbot.logout'))
            ->assertRedirect(route('chatbot.landing'))
            ->assertSessionMissing('resident_account_id');

        $this->get(route('chatbot.household.verification'))
            ->assertRedirect(route('chatbot.login'));
    }

    public function test_existing_resident_login_still_works_after_logout_route_added(): void
    {
        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'login.after.logout@example.com',
            'password' => Hash::make('ValidPass!123'),
        ]));

        $this->from(route('chatbot.login'))
            ->post(route('chatbot.login.store'), [
                'email' => 'login.after.logout@example.com',
                'password' => 'ValidPass!123',
            ])
            ->assertRedirect(route('chatbot.main'))
            ->assertSessionHas('resident_account_id');

        $this->assertNotNull(session('resident_account_id'));
    }
}
