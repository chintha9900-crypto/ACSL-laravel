<?php

namespace Tests\Feature\Authorization;

use App\Actions\Membership\StartRenewal;
use App\Models\Document;
use App\Models\User;
use App\Support\Authorization\Ability;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * RBAC foundation: which of the five approved roles may reach which existing
 * route, and the explicit security requirements around it (IDOR, privilege
 * escalation, fail-closed-by-default). `RoleAndPermissionTest` covers the
 * seeding/relationship layer this builds on.
 *
 * Today's existing admin routes happen to map onto permissions `editor`
 * holds for every one of them (content, commerce, orders, membership
 * review/activation, payment review) — there is no existing admin-only
 * route (user management, settings, audit log) to show `editor` denied at
 * the HTTP layer yet, since building those screens is explicitly out of
 * scope for this phase. `test_editor_lacks_admin_only_permissions` proves
 * the denial at the permission layer instead, which is what every future
 * admin-only route will be built against.
 */
class RoleAuthorizationTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    // --- admin ---------------------------------------------------------------

    public function test_admin_can_reach_every_existing_admin_route_family(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.blog.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.membership-applications.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.payments.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk();
    }

    // --- editor ----------------------------------------------------------------

    public function test_editor_can_reach_the_admin_dashboard_and_every_operational_route_family(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($editor)->get(route('admin.products.index'))->assertOk();
        $this->actingAs($editor)->get(route('admin.blog.index'))->assertOk();
        $this->actingAs($editor)->get(route('admin.membership-applications.index'))->assertOk();
        $this->actingAs($editor)->get(route('admin.payments.index'))->assertOk();
        $this->actingAs($editor)->get(route('admin.orders.index'))->assertOk();
    }

    public function test_editor_lacks_admin_only_permissions(): void
    {
        $editor = $this->editor();

        $this->assertFalse($editor->hasPermission(Ability::ManageUsers));
        $this->assertFalse($editor->hasPermission(Ability::ManageSettings));
        $this->assertFalse($editor->hasPermission(Ability::ViewAuditLog));
        $this->assertFalse($editor->hasPermission(Ability::ViewDiagnostics));
    }

    // --- member ----------------------------------------------------------------

    public function test_member_is_denied_the_entire_admin_area(): void
    {
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.dashboard'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.products.index'))->assertForbidden();
    }

    public function test_member_can_reach_their_own_dashboard(): void
    {
        // A plain member with no Membership row 404s (pre-existing, unrelated to
        // RBAC) — this needs a real activated membership to reach 200.
        $membership = $this->activatedMembership();

        $this->actingAs($membership->user)->get(route('member.dashboard'))->assertOk();
    }

    public function test_member_can_reach_other_permitted_dashboard_routes(): void
    {
        $this->actingAs($this->member())->get(route('member.profile.show'))->assertOk();
    }

    // --- the member dashboard group is member-only ---------------------------

    public function test_admin_cannot_access_member_only_dashboard_routes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('member.dashboard'))->assertForbidden();
        $this->actingAs($admin)->get(route('member.profile.show'))->assertForbidden();
    }

    public function test_editor_cannot_access_member_only_dashboard_routes(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)->get(route('member.dashboard'))->assertForbidden();
        $this->actingAs($editor)->get(route('member.profile.show'))->assertForbidden();
    }

    public function test_dev_cannot_access_member_only_dashboard_routes(): void
    {
        $dev = $this->dev();

        $this->actingAs($dev)->get(route('member.dashboard'))->assertForbidden();
        $this->actingAs($dev)->get(route('member.profile.show'))->assertForbidden();
    }

    public function test_guest_cannot_access_member_only_dashboard_routes(): void
    {
        $this->get(route('member.dashboard'))->assertRedirect(route('login'));
        $this->get(route('member.profile.show'))->assertRedirect(route('login'));
    }

    public function test_a_member_cannot_download_another_members_payment_evidence_document(): void
    {
        $owner = $this->renewableMembership(['email' => 'owner@example.test']);
        $term = app(StartRenewal::class)->handle($owner);
        $document = $term->payment->evidence()->create([
            'kind' => Document::KIND_PAYMENT_EVIDENCE,
            'payment_id' => $term->payment->id,
            'uploaded_by_user_id' => $owner->user_id,
            'disk' => 'private',
            'storage_path' => 'payment-evidence/owner-test.pdf',
            'original_filename' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', 'owner-evidence'),
        ]);

        $otherMember = $this->member();

        $this->actingAs($otherMember)
            ->get(route('member.documents.show', $document))
            ->assertForbidden();
    }

    // --- dev ---------------------------------------------------------------

    public function test_dev_can_reach_the_admin_dashboard_but_not_a_single_business_route(): void
    {
        $dev = $this->dev();

        $this->actingAs($dev)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($dev)->get(route('admin.products.index'))->assertForbidden();
        $this->actingAs($dev)->get(route('admin.blog.index'))->assertForbidden();
        $this->actingAs($dev)->get(route('admin.membership-applications.index'))->assertForbidden();
        $this->actingAs($dev)->get(route('admin.payments.index'))->assertForbidden();
        $this->actingAs($dev)->get(route('admin.orders.index'))->assertForbidden();
    }

    public function test_dev_cannot_confirm_a_payment(): void
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);

        $this->actingAs($this->dev())
            ->post(route('admin.payments.confirm', $term->payment))
            ->assertForbidden();
    }

    public function test_dev_cannot_review_a_membership_application(): void
    {
        $application = $this->application('submitted');

        $this->actingAs($this->dev())
            ->post(route('admin.membership-applications.review', $application), [
                'decision' => 'approved',
            ])
            ->assertForbidden();
    }

    public function test_dev_cannot_create_a_product(): void
    {
        $this->actingAs($this->dev())
            ->post(route('admin.products.store'), [])
            ->assertForbidden();
    }

    public function test_dev_holds_no_review_permission_and_so_cannot_open_aviation_proof(): void
    {
        $application = $this->application('submitted');
        $document = $this->proofDocument($application);

        $this->actingAs($this->dev())
            ->get(route('admin.documents.show', $document))
            ->assertForbidden();
    }

    public function test_an_editor_holding_the_review_permission_can_open_aviation_proof(): void
    {
        $application = $this->application('submitted');
        $document = $this->proofDocument($application);

        $this->actingAs($this->editor())
            ->get(route('admin.documents.show', $document))
            ->assertOk();
    }

    // --- guest ---------------------------------------------------------------

    public function test_guest_is_redirected_to_login_for_the_admin_and_member_areas(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('member.dashboard'))->assertRedirect(route('login'));
    }

    public function test_guest_can_reach_public_pages(): void
    {
        $this->get(route('home'))->assertOk();
    }

    // --- fail closed / privilege escalation -----------------------------------

    public function test_role_and_status_are_never_mass_assignable(): void
    {
        $this->assertNotContains('role', (new User)->getFillable());
        $this->assertNotContains('status', (new User)->getFillable());
    }

    public function test_a_user_with_an_unrecognised_in_memory_role_is_denied_the_admin_area(): void
    {
        $user = User::factory()->active()->create();
        $user->role = 'superadmin';

        $this->actingAs($user)->get(route('admin.dashboard'))->assertForbidden();
    }
}
