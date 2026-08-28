<?php

namespace Tests\Feature;

use App\Http\Controllers\Chatbot\ResidentForgotPasswordController;
use App\Http\Controllers\Chatbot\ResidentResetPasswordController;
use App\Mail\ResidentPasswordResetMail;
use App\Models\Resident;
use App\Models\ResidentAccount;
use App\Models\ResidentPasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResidentChatbotPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_PASSWORD = 'ValidPass!123';

    private const NEW_PASSWORD = 'NewPass!456';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
    }

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
            'email' => 'ana.reset@example.com',
            'password' => Hash::make(self::OLD_PASSWORD),
            'resident_id' => null,
        ], $overrides);
    }

    private function requestResetLink(string $email)
    {
        return $this->from(route('chatbot.password.request'))
            ->post(route('chatbot.password.email'), ['email' => $email]);
    }

    /**
     * @return array{token: string, email: string, url: string}
     */
    private function lastResetLink(): array
    {
        $mailable = Mail::sent(ResidentPasswordResetMail::class)->last();
        $this->assertNotNull($mailable);
        $this->assertNotSame('', $mailable->resetUrl);

        $parts = parse_url($mailable->resetUrl);
        $this->assertIsArray($parts);
        parse_str($parts['query'] ?? '', $query);

        return [
            'url' => $mailable->resetUrl,
            'token' => basename($parts['path'] ?? ''),
            'email' => $query['email'] ?? '',
        ];
    }

    public function test_forgot_password_page_returns_ok(): void
    {
        $html = $this->get(route('chatbot.password.request'))->assertOk()->getContent();

        $this->assertStringContainsString('Forgot Your Password?', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringNotContainsString('onsubmit="return false;"', $html);
    }

    public function test_reset_password_page_renders_from_reset_link_structure(): void
    {
        $html = $this->get(route('chatbot.password.reset', [
            'token' => 'preview-token',
            'email' => 'ana.reset@example.com',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Reset Your Password', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="password_confirmation"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('preview-token', $html);
        $this->assertStringContainsString('ana.reset@example.com', $html);
        $this->assertStringNotContainsString('onsubmit="return false;"', $html);
        $this->assertStringNotContainsString('account_id', $html);
        $this->assertStringNotContainsString('resident_id', $html);
    }

    public function test_runtime_uses_authoritative_resident_password_resets_schema(): void
    {
        $this->assertTrue(Schema::hasTable('resident_password_resets'));
        $this->assertFalse(Schema::hasTable('resident_password_reset_tokens'));
        $this->assertSame('resident_password_resets', (new ResidentPasswordResetToken)->getTable());
        $this->assertSame('reset_id', (new ResidentPasswordResetToken)->getKeyName());

        $columns = Schema::getColumnListing('resident_password_resets');
        foreach (['reset_id', 'account_id', 'reset_token', 'requested_at', 'expires_at', 'is_used', 'used_at', 'created_at'] as $column) {
            $this->assertContains($column, $columns);
        }
        $this->assertNotContains('email', $columns);
        $this->assertNotContains('token', $columns);
    }

    public function test_registered_email_creates_hashed_reset_row_and_sends_mail(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());

        $response = $this->requestResetLink('ana.reset@example.com');

        $response->assertRedirect(route('chatbot.password.request'));
        $response->assertSessionHas(
            'status',
            'If an account exists for that email, a password reset link has been sent.'
        );

        $this->assertDatabaseCount('resident_password_resets', 1);

        $row = ResidentPasswordResetToken::query()->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $account->account_id, (int) $row->account_id);
        $this->assertFalse($row->is_used);
        $this->assertNull($row->used_at);
        $this->assertNotNull($row->requested_at);
        $this->assertNotNull($row->expires_at);
        $this->assertNotNull($row->created_at);
        $this->assertEqualsWithDelta(60, $row->requested_at->diffInMinutes($row->expires_at), 1);

        $link = $this->lastResetLink();
        $this->assertNotSame($link['token'], $row->reset_token);
        $this->assertTrue(Hash::isHashed($row->reset_token));
        $this->assertTrue(Hash::check($link['token'], $row->reset_token));
        $this->assertStringNotContainsString($link['token'], (string) $row->reset_token);

        $this->assertSame($account->account_id, $account->passwordResets()->first()->account_id);
        $this->assertTrue($row->residentAccount->is($account));

        Mail::assertSent(ResidentPasswordResetMail::class, 1);
        Mail::assertSent(ResidentPasswordResetMail::class, function (ResidentPasswordResetMail $mail): bool {
            return $mail->hasTo('ana.reset@example.com');
        });
    }

    public function test_unregistered_email_receives_same_generic_response_without_row_or_mail(): void
    {
        $response = $this->requestResetLink('missing@example.com');

        $response->assertRedirect(route('chatbot.password.request'));
        $response->assertSessionHas(
            'status',
            'If an account exists for that email, a password reset link has been sent.'
        );

        $this->assertDatabaseCount('resident_password_resets', 0);
        Mail::assertNothingSent();
    }

    public function test_registered_and_unregistered_visible_status_messages_are_identical(): void
    {
        ResidentAccount::query()->create($this->accountAttributes());

        $registeredFollow = $this->from(route('chatbot.password.request'))
            ->followingRedirects()
            ->post(route('chatbot.password.email'), ['email' => 'ana.reset@example.com'])
            ->assertOk()
            ->getContent();

        Mail::fake();

        $unknownFollow = $this->from(route('chatbot.password.request'))
            ->followingRedirects()
            ->post(route('chatbot.password.email'), ['email' => 'nobody@example.com'])
            ->assertOk()
            ->getContent();

        $generic = 'If an account exists for that email, a password reset link has been sent.';
        $this->assertSame($generic, ResidentForgotPasswordController::STATUS_MESSAGE);
        $this->assertStringContainsString($generic, $registeredFollow);
        $this->assertStringContainsString($generic, $unknownFollow);
        $this->assertStringNotContainsString('not registered', strtolower($registeredFollow));
        $this->assertStringNotContainsString('account not found', strtolower($unknownFollow.$registeredFollow));
    }

    public function test_new_reset_request_invalidates_previous_unused_row(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());

        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $first = $this->lastResetLink();

        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $second = $this->lastResetLink();

        $this->assertDatabaseCount('resident_password_resets', 2);
        $this->assertNotSame($first['token'], $second['token']);

        $rows = ResidentPasswordResetToken::query()
            ->where('account_id', $account->account_id)
            ->orderBy('reset_id')
            ->get();

        $this->assertTrue($rows[0]->is_used);
        $this->assertNotNull($rows[0]->used_at);
        $this->assertFalse($rows[1]->is_used);
        $this->assertNull($rows[1]->used_at);
        $this->assertFalse(Hash::check($first['token'], $rows[1]->reset_token));
        $this->assertTrue(Hash::check($second['token'], $rows[1]->reset_token));

        $this->from($first['url'])
            ->post(route('chatbot.password.update'), [
                'token' => $first['token'],
                'email' => $first['email'],
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $account->fresh()->password));
    }

    public function test_token_is_bound_to_account_email_and_wrong_combination_fails(): void
    {
        ResidentAccount::query()->create($this->accountAttributes());
        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'other.reset@example.com',
            'first_name' => 'Other',
        ]));

        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $link = $this->lastResetLink();

        $this->from($link['url'])
            ->post(route('chatbot.password.update'), [
                'token' => $link['token'],
                'email' => 'other.reset@example.com',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, ResidentAccount::query()->where('email', 'ana.reset@example.com')->value('password')));
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, ResidentAccount::query()->where('email', 'other.reset@example.com')->value('password')));
        $this->assertDatabaseHas('resident_password_resets', [
            'account_id' => ResidentAccount::query()->where('email', 'ana.reset@example.com')->value('account_id'),
            'is_used' => false,
        ]);
    }

    public function test_expired_token_fails_and_preserves_history_row(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $link = $this->lastResetLink();

        $this->travel(ResidentResetPasswordController::EXPIRE_MINUTES + 1)->minutes();

        $this->from($link['url'])
            ->post(route('chatbot.password.update'), [
                'token' => $link['token'],
                'email' => $link['email'],
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $account->fresh()->password));
        $this->assertDatabaseCount('resident_password_resets', 1);
        $row = ResidentPasswordResetToken::query()->first();
        $this->assertFalse($row->is_used);
        $this->assertTrue($row->expires_at->lessThan(now()));
    }

    public function test_valid_token_resets_hashed_password_marks_used_and_cannot_be_reused(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $link = $this->lastResetLink();

        $resetPage = $this->get($link['url'])->assertOk()->getContent();
        $this->assertStringNotContainsString($account->password, $resetPage);
        $row = ResidentPasswordResetToken::query()->first();
        $this->assertStringNotContainsString($row->reset_token, $resetPage);
        $this->assertStringNotContainsString('account_id', $resetPage);
        $this->assertStringNotContainsString('resident_id', $resetPage);

        $this->from($link['url'])
            ->post(route('chatbot.password.update'), [
                'token' => $link['token'],
                'email' => $link['email'],
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ])
            ->assertRedirect(route('chatbot.login'))
            ->assertSessionHas('success', 'Your password has been reset. You can now log in.');

        $account->refresh();
        $this->assertNotSame(self::NEW_PASSWORD, $account->password);
        $this->assertTrue(Hash::isHashed($account->password));
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->password));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $account->password));

        $this->assertDatabaseCount('resident_password_resets', 1);
        $used = ResidentPasswordResetToken::query()->first();
        $this->assertTrue($used->is_used);
        $this->assertNotNull($used->used_at);

        $this->from($link['url'])
            ->post(route('chatbot.password.update'), [
                'token' => $link['token'],
                'email' => $link['email'],
                'password' => 'AnotherPass!789',
                'password_confirmation' => 'AnotherPass!789',
            ])
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $account->fresh()->password));
    }

    public function test_new_password_authenticates_and_old_password_does_not(): void
    {
        ResidentAccount::query()->create($this->accountAttributes());
        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $link = $this->lastResetLink();

        $this->post(route('chatbot.password.update'), [
            'token' => $link['token'],
            'email' => $link['email'],
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('chatbot.login'));

        $this->from(route('chatbot.login'))->post(route('chatbot.login.store'), [
            'email' => 'ana.reset@example.com',
            'password' => self::OLD_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->from(route('chatbot.login'))->post(route('chatbot.login.store'), [
            'email' => 'ana.reset@example.com',
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect(route('chatbot.main'))
            ->assertSessionHas('resident_account_id');
    }

    public function test_password_confirmation_and_policy_are_enforced(): void
    {
        ResidentAccount::query()->create($this->accountAttributes());
        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $link = $this->lastResetLink();

        $this->from($link['url'])->post(route('chatbot.password.update'), [
            'token' => $link['token'],
            'email' => $link['email'],
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'MismatchPass!1',
        ])->assertSessionHasErrors('password');

        $this->from($link['url'])->post(route('chatbot.password.update'), [
            'token' => $link['token'],
            'email' => $link['email'],
            'password' => 'short1!',
            'password_confirmation' => 'short1!',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, ResidentAccount::query()->where('email', 'ana.reset@example.com')->value('password')));
    }

    public function test_unlinked_resident_account_can_reset_password(): void
    {
        ResidentAccount::query()->create($this->accountAttributes([
            'resident_id' => null,
        ]));

        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $link = $this->lastResetLink();

        $this->post(route('chatbot.password.update'), [
            'token' => $link['token'],
            'email' => $link['email'],
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('chatbot.login'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, ResidentAccount::query()->where('email', 'ana.reset@example.com')->value('password')));
        $this->assertDatabaseHas('resident_accounts', [
            'email' => 'ana.reset@example.com',
            'resident_id' => null,
        ]);
    }

    public function test_official_residents_and_staff_users_are_not_modified(): void
    {
        $resident = Resident::factory()->create([
            'first_name' => 'OfficialKeep',
            'last_name' => 'Resident',
        ]);
        $staff = User::factory()->create([
            'email' => 'staff.reset@example.test',
            'username' => 'staff.reset',
            'password' => 'StaffPass!123',
        ]);
        $staffHash = $staff->password;

        ResidentAccount::query()->create($this->accountAttributes([
            'resident_id' => $resident->getAttribute(Resident::resolvedPrimaryKeyName()),
        ]));

        $this->requestResetLink('ana.reset@example.com')->assertRedirect();
        $link = $this->lastResetLink();

        $this->post(route('chatbot.password.update'), [
            'token' => $link['token'],
            'email' => $link['email'],
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('chatbot.login'));

        $this->assertSame('OfficialKeep', $resident->fresh()->first_name);
        $this->assertSame('Resident', $resident->fresh()->last_name);
        $this->assertSame($staffHash, $staff->fresh()->password);
        $this->assertTrue(Hash::check('StaffPass!123', $staff->fresh()->password));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertDatabaseCount('resident_password_resets', 1);
        $this->assertTrue(ResidentPasswordResetToken::query()->first()->is_used);
    }

    public function test_staff_email_does_not_create_resident_reset_token_or_send_mail(): void
    {
        User::factory()->create([
            'email' => 'staff.only@example.test',
            'username' => 'staff.only',
        ]);

        $this->requestResetLink('staff.only@example.test')
            ->assertRedirect(route('chatbot.password.request'))
            ->assertSessionHas(
                'status',
                'If an account exists for that email, a password reset link has been sent.'
            );

        $this->assertDatabaseCount('resident_password_resets', 0);
        Mail::assertNothingSent();
    }

    public function test_mail_does_not_include_passwords_or_ids(): void
    {
        $account = ResidentAccount::query()->create($this->accountAttributes());
        $this->requestResetLink('ana.reset@example.com')->assertRedirect();

        Mail::assertSent(ResidentPasswordResetMail::class, function (ResidentPasswordResetMail $mail) use ($account): bool {
            $html = $mail->render();

            $this->assertStringContainsString('expires in 60 minutes', strtolower($html));
            $this->assertStringContainsString('reset your password', strtolower($html));
            $this->assertStringNotContainsString(self::OLD_PASSWORD, $html);
            $this->assertStringNotContainsString(self::NEW_PASSWORD, $html);
            $this->assertStringNotContainsString($account->password, $html);
            $this->assertStringNotContainsString('account_id', $html);
            $this->assertStringNotContainsString('resident_id', $html);

            return true;
        });
    }

    public function test_forgot_password_post_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('chatbot.password.email'), [
                'email' => 'throttle-'.$i.'@example.com',
            ])->assertRedirect();
        }

        $this->post(route('chatbot.password.email'), [
            'email' => 'throttle-overflow@example.com',
        ])->assertStatus(429);

        ResidentAccount::query()->create($this->accountAttributes([
            'email' => 'throttle.known@example.com',
        ]));

        $this->post(route('chatbot.password.email'), [
            'email' => 'throttle.known@example.com',
        ])->assertStatus(429);

        Mail::assertNothingSent();
    }
}
