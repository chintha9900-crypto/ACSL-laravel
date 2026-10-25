<?php

namespace Tests\Feature\Authorization;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * Role-specific dashboards: /admin (admin), /editor (editor; admin too),
 * /dev (dev; admin too), /dashboard (member) — redirects, access matrix,
 * guests, inactive accounts, and the editor/dev boundaries they enforce.
 */
class RoleDashboardsTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private function userWithRole(string $role): User
    {
        return match ($role) {
            'admin' => $this->admin(),
            'editor' => $this->editor(),
            'dev' => $this->dev(),
            'member' => $this->member(),
        };
    }

    /**
     * Staff dashboards only; the member dashboard is covered by its own tests
     * (it 404s for a member without a membership, by design).
     *
     * @return array<string, array{string, string, int}>
     */
    public static function dashboardAccess(): array
    {
        return [
            'admin → /admin' => ['admin', 'admin.dashboard', 200],
            'admin → /editor' => ['admin', 'editor.dashboard', 200],
            'admin → /dev' => ['admin', 'dev.dashboard', 200],
            'admin → /dashboard' => ['admin', 'member.dashboard', 403],
            'editor → /admin' => ['editor', 'admin.dashboard', 403],
            'editor → /editor' => ['editor', 'editor.dashboard', 200],
            'editor → /dev' => ['editor', 'dev.dashboard', 403],
            'editor → /dashboard' => ['editor', 'member.dashboard', 403],
            'dev → /admin' => ['dev', 'admin.dashboard', 403],
            'dev → /editor' => ['dev', 'editor.dashboard', 403],
            'dev → /dev' => ['dev', 'dev.dashboard', 200],
            'dev → /dashboard' => ['dev', 'member.dashboard', 403],
            'member → /admin' => ['member', 'admin.dashboard', 403],
            'member → /editor' => ['member', 'editor.dashboard', 403],
            'member → /dev' => ['member', 'dev.dashboard', 403],
        ];
    }

    #[DataProvider('dashboardAccess')]
    public function test_each_dashboard_is_reachable_only_by_its_roles(string $role, string $route, int $status): void
    {
        $this->actingAs($this->userWithRole($role))->get(route($route))->assertStatus($status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function staffDashboards(): array
    {
        return [
            'admin' => ['admin.dashboard'],
            'editor' => ['editor.dashboard'],
            'dev' => ['dev.dashboard'],
            'member' => ['member.dashboard'],
        ];
    }

    #[DataProvider('staffDashboards')]
    public function test_guests_are_sent_to_login(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function blockedAccounts(): array
    {
        return [
            'suspended editor' => ['editor', 'suspended', 'editor.dashboard'],
            'pending-setup editor' => ['editor', 'pending_setup', 'editor.dashboard'],
            'suspended dev' => ['dev', 'suspended', 'dev.dashboard'],
            'suspended admin' => ['admin', 'suspended', 'admin.dashboard'],
        ];
    }

    #[DataProvider('blockedAccounts')]
    public function test_inactive_accounts_are_blocked(string $role, string $status, string $route): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status]);

        $this->actingAs($user)->get(route($route))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function loginDestinations(): array
    {
        return [
            'admin' => ['admin', 'admin.dashboard'],
            'editor' => ['editor', 'editor.dashboard'],
            'dev' => ['dev', 'dev.dashboard'],
            'member' => ['member', 'member.dashboard'],
        ];
    }

    #[DataProvider('loginDestinations')]
    public function test_an_already_signed_in_user_visiting_login_goes_to_their_own_dashboard(string $role, string $route): void
    {
        $this->actingAs($this->userWithRole($role))->get(route('login'))->assertRedirect(route($route));
    }

    public function test_each_layout_dashboard_link_points_to_the_roles_own_dashboard(): void
    {
        $this->actingAs($this->editor())->get(route('editor.dashboard'))
            ->assertSee('href="'.route('editor.dashboard').'"', false)
            ->assertDontSee('href="'.route('admin.dashboard').'"', false);

        $this->actingAs($this->dev())->get(route('dev.dashboard'))
            ->assertSee('href="'.route('dev.dashboard').'"', false)
            ->assertDontSee('href="'.route('admin.dashboard').'"', false);
    }

    // --- editor ----------------------------------------------------------------

    public function test_the_editor_dashboard_links_to_every_editor_section_and_not_to_admin_tools(): void
    {
        $response = $this->actingAs($this->editor())->get(route('editor.dashboard'))->assertOk();

        foreach (['admin.membership-applications.index', 'admin.payments.index', 'admin.blog.index', 'admin.news.index', 'admin.events.index', 'admin.products.index', 'admin.orders.index', 'admin.reports.eshop'] as $section) {
            $response->assertSee('href="'.route($section).'"', false);
        }
        $response->assertDontSee('Invite an editor')
            ->assertDontSee('href="'.route('dev.dashboard').'"', false);
    }

    public function test_an_editor_can_refund_an_order_review_payments_and_view_the_eshop_financial_summary(): void
    {
        $editor = $this->editor();
        $order = Order::factory()->create(['status' => Order::STATUS_PAID, 'currency' => 'LKR', 'total_amount' => 1500]);

        $this->actingAs($editor)->get(route('admin.payments.index'))->assertOk();
        $this->actingAs($editor)->get(route('admin.orders.index'))->assertOk();

        $this->actingAs($editor)
            ->get(route('admin.reports.eshop'))
            ->assertOk()
            ->assertSee('E-shop financial summary')
            ->assertSee('Paid')
            ->assertSee('LKR')
            ->assertSee('1,500.00');

        $this->actingAs($editor)
            ->patch(route('admin.orders.update-status', $order), ['status' => Order::STATUS_REFUNDED])
            ->assertRedirect(route('admin.orders.show', $order));
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
    }

    public function test_the_eshop_summary_groups_by_status_and_currency_within_the_date_range(): void
    {
        Order::factory()->create(['status' => Order::STATUS_PAID, 'currency' => 'LKR', 'total_amount' => 100]);
        Order::factory()->create(['status' => Order::STATUS_PAID, 'currency' => 'LKR', 'total_amount' => 250]);
        Order::factory()->create(['status' => Order::STATUS_REFUNDED, 'currency' => 'LKR', 'total_amount' => 40]);
        Order::factory()->create(['status' => Order::STATUS_PAID, 'currency' => 'LKR', 'total_amount' => 9999, 'created_at' => now()->subYear()]);

        $this->actingAs($this->editor())
            ->get(route('admin.reports.eshop'))
            ->assertOk()
            ->assertSeeInOrder(['Paid', 'LKR', '2', '350.00', 'Refunded', 'LKR', '1', '40.00'])
            ->assertDontSee('9,999.00');

        $this->actingAs($this->editor())
            ->get(route('admin.reports.eshop', ['from' => '2026-12-01', 'to' => '2026-01-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_the_editor_holds_no_user_administration(): void
    {
        $this->actingAs($this->editor())
            ->post(route('admin.editor-invitations.store'), ['email' => 'new.editor@example.test', 'role' => 'editor'])
            ->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new.editor@example.test']);
    }

    // --- dev -------------------------------------------------------------------

    public function test_dev_has_no_financial_operations_user_administration_or_member_data(): void
    {
        $dev = $this->dev();
        $order = Order::factory()->create(['status' => Order::STATUS_PAID]);

        foreach (['admin.payments.index', 'admin.orders.index', 'admin.reports.eshop', 'admin.membership-applications.index', 'admin.products.index'] as $route) {
            $this->actingAs($dev)->get(route($route))->assertForbidden();
        }
        $this->actingAs($dev)->get(route('admin.orders.show', $order))->assertForbidden();
        $this->actingAs($dev)->patch(route('admin.orders.update-status', $order), ['status' => Order::STATUS_REFUNDED])->assertForbidden();
        $this->actingAs($dev)->post(route('admin.editor-invitations.store'), ['email' => 'x@example.test', 'role' => 'editor'])->assertForbidden();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseMissing('users', ['email' => 'x@example.test']);
    }

    public function test_the_dev_dashboard_shows_health_and_audit_events_without_personal_data_or_secrets(): void
    {
        $subject = User::factory()->active()->create(['name' => 'Private Person', 'email' => 'private.person@example.test']);
        DB::table('audit_logs')->insert([
            'user_id' => $subject->id,
            'actor_type' => 'user',
            'event' => 'membership.activated',
            'subject_type' => 'membership',
            'subject_id' => 42,
            'old_values' => json_encode(['email' => 'old.secret@example.test']),
            'new_values' => json_encode(['membership_number' => 'S26750099']),
            'ip_address' => '203.0.113.77',
            'user_agent' => 'SecretAgent/1.0',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->dev())->get(route('dev.dashboard'))->assertOk();

        $response->assertSee('System health')
            ->assertSee('Database')
            ->assertSee('Connected')
            ->assertSee('Recent audit log')
            ->assertSee('membership.activated')
            ->assertSee('membership #42');

        foreach (['private.person@example.test', 'Private Person', 'old.secret@example.test', 'S26750099', '203.0.113.77', 'SecretAgent/1.0', (string) config('app.key'), (string) config('database.connections.mysql.password')] as $private) {
            if ($private !== '') {
                $response->assertDontSee($private);
            }
        }
        $this->assertSame(0, substr_count($response->getContent(), '<form method="POST" action="'.url('/admin')), 'The dev dashboard offers no admin actions.');
    }

    public function test_the_admin_dashboard_links_to_the_other_role_dashboards_and_offers_the_invitation(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('href="'.route('editor.dashboard').'"', false)
            ->assertSee('href="'.route('dev.dashboard').'"', false)
            ->assertSee('Invite an editor')
            ->assertSee('action="'.route('admin.editor-invitations.store').'"', false);
    }
}
