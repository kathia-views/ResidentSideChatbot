<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\ResidentAccount;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ResidentAccountResidentLinkTest extends TestCase
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
            'zone_purok' => '1',
            'email' => 'ana.santos@example.com',
            'password' => Hash::make('ValidPass!123'),
            'resident_id' => null,
        ], $overrides);
    }

    private function chatbotRelationshipKey(Resident $resident): mixed
    {
        return $resident->getAttribute(Resident::resolvedPrimaryKeyName());
    }

    public function test_resident_account_can_exist_with_null_resident_id(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());

        $this->assertNull($account->resident_id);
        $this->assertDatabaseHas('resident_accounts', [
            'account_id' => $account->account_id,
            'email' => 'ana.santos@example.com',
            'resident_id' => null,
        ]);
        $this->assertNull($account->resident);
    }

    public function test_resident_account_can_be_linked_to_a_resident(): void
    {
        $resident = Resident::factory()->create();

        $relationshipKey = $this->chatbotRelationshipKey($resident);

        $account = ResidentAccount::query()->create($this->accountAttributes([
            'resident_id' => $relationshipKey,
        ]));

        $this->assertSame($relationshipKey, $account->resident_id);
        $this->assertTrue($account->resident->is($resident));
        $this->assertTrue($resident->residentAccount->is($account));
    }

    public function test_database_rejects_two_accounts_linked_to_the_same_resident(): void
    {
        $resident = Resident::factory()->create();

        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'first@example.com',
            'resident_id' => $this->chatbotRelationshipKey($resident),
        ]));

        $this->expectException(QueryException::class);

        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'second@example.com',
            'resident_id' => $this->chatbotRelationshipKey($resident),
        ]));
    }

    public function test_chatbot_registration_succeeds_without_resident_id(): void
    {
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);

        $response = $this->from(route('chatbot.register'))->post(route('chatbot.register.store'), [
            'first_name' => 'Liza',
            'middle_name' => 'Mae',
            'last_name' => 'Reyes',
            'zone' => '2',
            'email' => 'liza.reyes@example.com',
            'password' => 'ValidPass!123',
        ]);

        $response->assertRedirect(route('chatbot.login'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('resident_accounts', [
            'email' => 'liza.reyes@example.com',
            'first_name' => 'Liza',
            'zone_purok' => '2',
            'resident_id' => null,
        ]);
    }

    public function test_chatbot_login_succeeds_for_unlinked_account(): void
    {
        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'login.user@example.com',
            'password' => Hash::make('ValidPass!123'),
        ]));

        $response = $this->from(route('chatbot.login'))->post(route('chatbot.login.store'), [
            'email' => 'login.user@example.com',
            'password' => 'ValidPass!123',
        ]);

        $response->assertRedirect(route('chatbot.main'));
        $response->assertSessionHas('resident_account_id');
        $this->assertNotNull(session('resident_account_id'));
    }
}
