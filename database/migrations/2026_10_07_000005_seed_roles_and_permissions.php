<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The five approved roles (Guest excluded — it has no database row) and
     * every granular permission, plus which roles hold which. Reference
     * data, not application logic — same status, and same `insertOrIgnore`
     * safe-to-rerun convention, as the membership-category reference-data
     * migration (`2026_09_29_000003`). This is the one place "what can each
     * role do" is recorded; `App\Support\Authorization\Ability` is the PHP
     * enum of the same permission names, checked by Policies at runtime.
     *
     * Grants, approved:
     * - admin: every permission (also has blanket access via `Gate::before`,
     *   independent of this table — this seed keeps the two consistent).
     * - editor: content manage/publish, commerce, orders, membership
     *   application review/activation, payment review. No user/settings/
     *   audit/diagnostics permission.
     * - member: none — a member's own-data access is enforced by existing
     *   ownership checks in Policies, not this permission system.
     * - dev: audit log (read-only) and diagnostics only. No business,
     *   approval, payment or user-management permission.
     */
    public function up(): void
    {
        $roles = [
            ['name' => 'admin', 'label' => 'Admin'],
            ['name' => 'editor', 'label' => 'Editor'],
            ['name' => 'member', 'label' => 'Member'],
            ['name' => 'dev', 'label' => 'Dev'],
        ];

        $permissions = [
            ['name' => 'content.manage', 'label' => 'Manage content'],
            ['name' => 'content.publish', 'label' => 'Publish content'],
            ['name' => 'commerce.manage', 'label' => 'Manage products & inventory'],
            ['name' => 'orders.manage', 'label' => 'Manage orders'],
            ['name' => 'membership.applications.review', 'label' => 'Review membership applications'],
            ['name' => 'membership.activate', 'label' => 'Activate memberships'],
            ['name' => 'payments.review', 'label' => 'Review payments'],
            ['name' => 'users.manage', 'label' => 'Manage user roles & accounts'],
            ['name' => 'settings.manage', 'label' => 'Manage system settings'],
            ['name' => 'audit.view', 'label' => 'View audit log'],
            ['name' => 'diagnostics.view', 'label' => 'View technical diagnostics'],
        ];

        $now = now();

        DB::table('roles')->insertOrIgnore(array_map(
            fn (array $role) => [...$role, 'created_at' => $now, 'updated_at' => $now],
            $roles,
        ));

        DB::table('permissions')->insertOrIgnore(array_map(
            fn (array $permission) => [...$permission, 'created_at' => $now, 'updated_at' => $now],
            $permissions,
        ));

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permissionIds = DB::table('permissions')->pluck('id', 'name');

        $grants = [
            'admin' => array_column($permissions, 'name'),
            'editor' => [
                'content.manage',
                'content.publish',
                'commerce.manage',
                'orders.manage',
                'membership.applications.review',
                'membership.activate',
                'payments.review',
            ],
            'member' => [],
            'dev' => [
                'audit.view',
                'diagnostics.view',
            ],
        ];

        $rows = [];

        foreach ($grants as $roleName => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                $rows[] = [
                    'role_id' => $roleIds[$roleName],
                    'permission_id' => $permissionIds[$permissionName],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('permission_role')->insertOrIgnore($rows);
    }

    /**
     * Reverse the migrations.
     *
     * Fails (by FK RESTRICT from `users.role`, added in the next migration —
     * which rolls back before this one) if any user still holds one of
     * these roles — intentional, same reasoning as every other
     * reference-data migration's `down()` in this project.
     */
    public function down(): void
    {
        DB::table('permission_role')
            ->whereIn('role_id', function ($query) {
                $query->select('id')->from('roles')->whereIn('name', ['admin', 'editor', 'member', 'dev']);
            })
            ->delete();

        DB::table('permissions')->whereIn('name', [
            'content.manage',
            'content.publish',
            'commerce.manage',
            'orders.manage',
            'membership.applications.review',
            'membership.activate',
            'payments.review',
            'users.manage',
            'settings.manage',
            'audit.view',
            'diagnostics.view',
        ])->delete();

        DB::table('roles')->whereIn('name', ['admin', 'editor', 'member', 'dev'])->delete();
    }
};
