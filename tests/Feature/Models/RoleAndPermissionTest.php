<?php

namespace Tests\Feature\Models;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Ability;
use Illuminate\Database\QueryException;
use Tests\MysqlTestCase;

/**
 * RBAC foundation: role/permission seeding and the role-permission and
 * user-role relationships. Does not cover whether a permission actually
 * guards anything — that is `RoleAuthorizationTest`.
 */
class RoleAndPermissionTest extends MysqlTestCase
{
    // --- seeding ---------------------------------------------------------

    public function test_the_four_approved_roles_are_seeded(): void
    {
        $this->assertSame(4, Role::query()->count());
        $this->assertEqualsCanonicalizing(
            ['admin', 'editor', 'member', 'dev'],
            Role::query()->pluck('name')->all(),
        );
    }

    public function test_there_is_no_database_row_for_guest(): void
    {
        $this->assertFalse(Role::query()->where('name', 'guest')->exists());
    }

    public function test_every_ability_is_seeded_as_a_permission(): void
    {
        $seeded = Permission::query()->pluck('name')->all();

        foreach (Ability::cases() as $ability) {
            $this->assertContains($ability->value, $seeded, "Missing seeded permission for {$ability->value}");
        }
    }

    // --- role-permission relationship -------------------------------------

    public function test_admin_holds_every_permission(): void
    {
        $admin = Role::query()->where('name', Role::ADMIN)->first();

        $this->assertSame(count(Ability::cases()), $admin->permissions()->count());
    }

    public function test_editor_holds_only_the_approved_operational_permissions(): void
    {
        $editor = Role::query()->where('name', Role::EDITOR)->first();
        $names = $editor->permissions()->pluck('name')->all();

        $this->assertEqualsCanonicalizing([
            Ability::ManageContent->value,
            Ability::PublishContent->value,
            Ability::ManageCommerce->value,
            Ability::ManageOrders->value,
            Ability::ReviewMembershipApplications->value,
            Ability::ActivateMembership->value,
            Ability::ReviewPayments->value,
        ], $names);

        $this->assertNotContains(Ability::ManageUsers->value, $names);
        $this->assertNotContains(Ability::ManageSettings->value, $names);
        $this->assertNotContains(Ability::ViewAuditLog->value, $names);
        $this->assertNotContains(Ability::ViewDiagnostics->value, $names);
    }

    public function test_member_holds_no_permission(): void
    {
        $member = Role::query()->where('name', Role::MEMBER)->first();

        $this->assertSame(0, $member->permissions()->count());
    }

    public function test_dev_holds_only_read_only_technical_permissions(): void
    {
        $dev = Role::query()->where('name', Role::DEV)->first();
        $names = $dev->permissions()->pluck('name')->all();

        $this->assertEqualsCanonicalizing([
            Ability::ViewAuditLog->value,
            Ability::ViewDiagnostics->value,
        ], $names);
    }

    public function test_a_permission_knows_which_roles_hold_it(): void
    {
        $permission = Permission::query()->where('name', Ability::ManageContent->value)->first();
        $roleNames = $permission->roles()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['admin', 'editor'], $roleNames);
    }

    // --- user-role relationship ---------------------------------------------

    public function test_a_user_resolves_its_role_model(): void
    {
        $user = User::factory()->active()->editor()->create();

        $this->assertTrue($user->roleModel->is(Role::query()->where('name', Role::EDITOR)->first()));
        $this->assertSame('Editor', $user->roleModel->label);
    }

    public function test_haspermission_reflects_the_seeded_grants_for_each_role(): void
    {
        $admin = User::factory()->active()->admin()->create();
        $editor = User::factory()->active()->editor()->create();
        $member = User::factory()->active()->create();
        $dev = User::factory()->active()->dev()->create();

        $this->assertTrue($admin->hasPermission(Ability::ManageUsers));
        $this->assertTrue($editor->hasPermission(Ability::ManageContent));
        $this->assertFalse($editor->hasPermission(Ability::ManageUsers));
        $this->assertFalse($member->hasPermission(Ability::ManageContent));
        $this->assertTrue($dev->hasPermission(Ability::ViewAuditLog));
        $this->assertFalse($dev->hasPermission(Ability::ReviewPayments));
    }

    // --- fail closed ---------------------------------------------------------

    public function test_a_suspended_user_holds_no_permission_regardless_of_role(): void
    {
        $suspendedAdmin = User::factory()->suspended()->admin()->create();

        $this->assertFalse($suspendedAdmin->hasPermission(Ability::ManageUsers));
    }

    public function test_an_unrecognised_in_memory_role_holds_no_permission(): void
    {
        $user = User::factory()->active()->create();
        $user->role = 'bogus';

        $this->assertFalse($user->hasPermission(Ability::ManageContent));
    }

    public function test_a_null_in_memory_role_holds_no_permission(): void
    {
        $user = User::factory()->active()->create();
        $user->role = null;

        $this->assertFalse($user->hasPermission(Ability::ManageContent));
    }

    // --- database integrity ---------------------------------------------------

    public function test_the_database_rejects_a_role_name_outside_the_roles_table(): void
    {
        $this->assertThrows(
            fn () => User::factory()->create(['role' => 'superadmin']),
            QueryException::class,
        );
    }

    public function test_the_database_rejects_a_duplicate_permission_role_grant(): void
    {
        $role = Role::query()->where('name', Role::EDITOR)->first();
        $permission = Permission::query()->where('name', Ability::ManageContent->value)->first();

        $this->assertThrows(
            fn () => $role->permissions()->attach($permission->id),
            QueryException::class,
        );
    }
}
