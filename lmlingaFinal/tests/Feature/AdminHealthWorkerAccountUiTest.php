<?php

namespace Tests\Feature;

use App\Support\UiRole;
use Tests\TestCase;

class AdminHealthWorkerAccountUiTest extends TestCase
{
    private const TEST_ADMIN_EMAIL = 'admin@example.test';

    private const TEST_ADMIN_PASSWORD = 'test-admin-password';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'demo.staff_accounts' => [
                [
                    'email' => self::TEST_ADMIN_EMAIL,
                    'password' => self::TEST_ADMIN_PASSWORD,
                    'shell_role' => 'admin',
                    'display_name' => 'Test Admin',
                    'identities' => [self::TEST_ADMIN_EMAIL],
                ],
            ],
        ]);
    }

    public function test_admin_manage_health_workers_links_to_create_account(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('user-management.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('href="'.e(route('user-management.health-workers.create')).'"', $html);
        $this->assertStringContainsString('Add Health Worker', $html);
        $this->assertStringNotContainsString('ADD is a UI placeholder', $html);
    }

    public function test_admin_create_account_screen_is_inside_user_management(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('user-management.health-workers.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Create Account', $html);
        $this->assertStringContainsString('Temporary Password', $html);
        $this->assertStringContainsString('Confirm Password', $html);
        $this->assertStringContainsString('Back to Manage Health Workers', $html);
        $this->assertStringContainsString('data-lml-hw-create', $html);
        $this->assertStringContainsString('lml-sidebar__link--active', $html);
        $this->assertStringContainsString('>User Management</span>', $html);
    }

    public function test_bhw_cannot_open_create_health_worker(): void
    {
        $this->withSession([UiRole::SESSION_KEY => 'bhw'])
            ->get(route('user-management.health-workers.create'))
            ->assertForbidden();
    }

    public function test_admin_dashboard_exposes_user_management_after_demo_login(): void
    {
        $this->post(route('login.store'), [
            'email' => self::TEST_ADMIN_EMAIL,
            'password' => self::TEST_ADMIN_PASSWORD,
        ])->assertRedirect(route('dashboard'));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('>User Management</span>', $html);
        $this->assertStringContainsString('href="'.e(route('user-management.index')).'"', $html);

        $this->get(route('user-management.health-workers.create'))->assertOk();
    }

    public function test_create_form_does_not_hardcode_demo_worker_redirect(): void
    {
        $html = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('user-management.health-workers.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-profile-url', $html);
        $this->assertStringNotContainsString('/health-workers/hw-001/view', $html);
    }

    public function test_worker_profile_and_edit_account_details_are_wired(): void
    {
        $view = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('user-management.health-workers.view', ['id' => 'hw-001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Edit Account Details', $view);
        $this->assertStringContainsString('Back to Manage Health Workers', $view);
        $this->assertStringContainsString(route('user-management.health-workers.edit', ['id' => 'hw-001']), $view);

        $edit = $this->withSession([UiRole::SESSION_KEY => 'admin'])
            ->get(route('user-management.health-workers.edit', ['id' => 'hw-001']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Edit Account Details', $edit);
        $this->assertStringContainsString('data-hw-wizard-save', $edit);
        $this->assertStringContainsString('data-hw-wizard-cancel', $edit);
        $this->assertStringContainsString('Back to Manage Health Workers', $edit);
    }
}
