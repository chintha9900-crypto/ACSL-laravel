<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\MysqlTestCase;

/**
 * RBAC foundation — where a signed-in user lands, decided purely server-side
 * from the authenticated user's own `role` column. One shared login page and
 * route serves every role; nothing here is role-specific.
 */
class RoleBasedRedirectTest extends MysqlTestCase
{
    private function login(User $user, array $overrides = []): TestResponse
    {
        return $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
            ...$overrides,
        ]);
    }

    public function test_admin_login_lands_on_the_admin_dashboard(): void
    {
        $this->login(User::factory()->active()->admin()->create())
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_editor_login_lands_on_the_admin_dashboard(): void
    {
        $this->login(User::factory()->active()->editor()->create())
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_dev_login_lands_on_the_admin_dashboard(): void
    {
        $this->login(User::factory()->active()->dev()->create())
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_member_login_lands_on_the_member_dashboard(): void
    {
        $this->login(User::factory()->active()->create())
            ->assertRedirect(route('member.dashboard'));
    }

    public function test_a_forged_role_field_in_the_login_request_has_no_effect(): void
    {
        $member = User::factory()->active()->create();

        $this->login($member, ['role' => 'admin'])
            ->assertRedirect(route('member.dashboard'));

        $this->assertSame('member', $member->fresh()->role);
    }

    public function test_an_intended_url_still_takes_priority_over_the_role_default(): void
    {
        $admin = User::factory()->active()->admin()->create();

        // Visiting a protected page while signed out records it as "intended";
        // logging in from there should return there, not to the role default.
        $this->get(route('admin.products.index'));

        $this->login($admin)->assertRedirect(route('admin.products.index'));
    }

    public function test_an_already_authenticated_admin_visiting_login_bounces_to_the_admin_dashboard(): void
    {
        $admin = User::factory()->active()->admin()->create();

        $this->actingAs($admin)
            ->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_an_already_authenticated_member_visiting_login_bounces_to_the_member_dashboard(): void
    {
        $member = User::factory()->active()->create();

        $this->actingAs($member)
            ->get(route('login'))
            ->assertRedirect(route('member.dashboard'));
    }
}
