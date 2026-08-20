<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequiredPasswordChangeUiTest extends TestCase
{
    public function test_required_change_password_screen_has_no_skip_or_dashboard_cta(): void
    {
        $html = $this->get(route('password.change.required'))->assertOk()->getContent();

        $this->assertStringContainsString('Change Password', $html);
        $this->assertStringContainsString('temporary password must be replaced', $html);
        $this->assertStringContainsString('New Password', $html);
        $this->assertStringContainsString('Confirm New Password', $html);
        $this->assertStringContainsString('Update Password', $html);

        // Shared layout accessibility control is allowed.
        $this->assertMatchesRegularExpression(
            '/<a[^>]*href="#main-content"[^>]*>\s*Skip to main content\s*<\/a>/i',
            $html
        );

        // Password-change bypass CTAs remain prohibited.
        $this->assertStringNotContainsString('>Skip</', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Skip\s*<\/button>/i', $html);
        $this->assertStringNotContainsString('Maybe later', $html);
        $this->assertStringNotContainsString(route('dashboard'), $html);
        $this->assertStringNotContainsString('href="'.e(route('login')).'"', $html);
    }
}
