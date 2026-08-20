<?php

namespace Tests\Feature;

use App\Support\DemoStaffLogin;
use App\Support\UiRole;
use Tests\TestCase;

class DemoStaffLoginFlowTest extends TestCase
{
    private const TEST_ADMIN_EMAIL = 'admin@example.test';

    private const TEST_ADMIN_PASSWORD = 'test-admin-password';

    private const TEST_ADMIN_NAME = 'Test Admin';

    private const TEST_WORKER_EMAIL = 'worker@example.test';

    private const TEST_WORKER_PASSWORD = 'test-worker-password';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'demo.staff_accounts' => [
                [
                    'email' => self::TEST_ADMIN_EMAIL,
                    'password' => self::TEST_ADMIN_PASSWORD,
                    'shell_role' => 'admin',
                    'display_name' => self::TEST_ADMIN_NAME,
                    'identities' => [self::TEST_ADMIN_EMAIL],
                ],
                [
                    'email' => self::TEST_WORKER_EMAIL,
                    'password' => self::TEST_WORKER_PASSWORD,
                    'shell_role' => 'bhw',
                    'display_name' => 'Test Worker',
                    'identities' => [self::TEST_WORKER_EMAIL],
                ],
            ],
        ]);
    }

    public function test_login_form_posts_credentials_and_never_uses_get(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*method="post"[^>]*data-lml-staff-login/u',
            $html
        );
        $this->assertStringContainsString('action="'.e(route('login.store')).'"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringNotContainsString('method="get"', $html);
        $this->assertStringNotContainsString('name="full_name"', $html);
        $this->assertStringNotContainsString('href="'.e(route('register')).'"', $html);
        $this->assertStringNotContainsString('>Register</', $html);
        $this->assertStringNotContainsString('Create Account', $html);

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="email"[^>]*(?!disabled)[^>]*>/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="password"[^>]*type="password"[^>]*>|<input[^>]*type="password"[^>]*id="password"[^>]*>/u',
            $html
        );
        $this->assertStringNotContainsString('id="email" disabled', $html);
        $this->assertStringNotContainsString('id="password" disabled', $html);
        $this->assertStringNotContainsString('disabled id="email"', $html);
        $this->assertStringNotContainsString('disabled id="password"', $html);

        // Forgot Password must sit below the password field, not inside an input-group / input wrapper.
        $this->assertDoesNotMatchRegularExpression(
            '/id="password"[\s\S]{0,400}?input-group[\s\S]{0,400}?Forgot Password\?/u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/id="password"[\s\S]*?<\/div>\s*<div class="lml-login-forgot">[\s\S]*?Forgot Password\?/u',
            $html
        );
    }

    public function test_valid_demo_admin_credentials_reach_admin_dashboard(): void
    {
        $response = $this->post(route('login.store'), [
            'email' => self::TEST_ADMIN_EMAIL,
            'password' => self::TEST_ADMIN_PASSWORD,
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas(UiRole::SESSION_KEY, 'admin');
        $response->assertSessionHas(DemoStaffLogin::SESSION_DISPLAY_NAME, self::TEST_ADMIN_NAME);

        $dashboard = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('>User Management</span>', $dashboard);
        $this->assertStringContainsString(self::TEST_ADMIN_NAME, $dashboard);
        $this->assertStringContainsString('href="'.e(route('user-management.index')).'"', $dashboard);
    }

    public function test_invalid_credentials_remain_on_login_with_accessible_error(): void
    {
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => self::TEST_ADMIN_EMAIL,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $response->assertSessionMissing(UiRole::SESSION_KEY);

        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('password=', $location);
        $this->assertStringNotContainsString('wrong-password', $location);

        $html = $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('Invalid email or password', $html);
    }

    public function test_demo_worker_login_cannot_open_admin_create_health_worker(): void
    {
        $this->post(route('login.store'), [
            'email' => self::TEST_WORKER_EMAIL,
            'password' => self::TEST_WORKER_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $this->assertSame('bhw', session(UiRole::SESSION_KEY));

        $this->get(route('user-management.health-workers.create'))->assertForbidden();
    }

    public function test_password_is_not_echoed_into_redirect_url_on_success(): void
    {
        $response = $this->post(route('login.store'), [
            'email' => self::TEST_ADMIN_EMAIL,
            'password' => self::TEST_ADMIN_PASSWORD,
        ]);

        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('password=', $location);
        $this->assertStringNotContainsString(self::TEST_ADMIN_PASSWORD, $location);
        $this->assertSame(route('dashboard'), $location);
    }

    public function test_demo_login_does_not_require_staff_login_php_fixture(): void
    {
        $source = file_get_contents((new \ReflectionClass(DemoStaffLogin::class))->getFileName());
        $this->assertIsString($source);
        $this->assertStringNotContainsString('staff-login.php', $source);
        $this->assertStringNotContainsString("resource_path('demo/", $source);
        $this->assertStringNotContainsString('require ', $source);
        $this->assertStringContainsString("config('demo.staff_accounts'", $source);
        $this->assertNotEmpty(DemoStaffLogin::accounts());
    }
}
