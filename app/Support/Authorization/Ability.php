<?php

namespace App\Support\Authorization;

/**
 * Every granular permission a role can hold (RBAC foundation). Each case's
 * string value is exactly the `permissions.name` row it corresponds to —
 * seeded by `2026_10_07_000005_seed_roles_and_permissions.php` and granted to
 * roles via the `permission_role` table. `User::hasPermission()` is the only
 * place that reads these at runtime; nothing compares `User::$role` directly
 * against a role name except the `role` route middleware and the admin
 * `Gate::before` bypass, both of which only ever decide "may this role reach
 * the admin area at all," never a specific business action.
 */
enum Ability: string
{
    /** Create/update/delete Blog, News, Events, CSR and Commercial Partner entries. */
    case ManageContent = 'content.manage';

    /** Publish/unpublish Blog, News, Events and CSR entries. */
    case PublishContent = 'content.publish';

    /** Manage products, product categories and inventory. */
    case ManageCommerce = 'commerce.manage';

    /** View and update the status of e-shop orders. */
    case ManageOrders = 'orders.manage';

    /** View, approve, reject and request more details on membership applications. */
    case ReviewMembershipApplications = 'membership.applications.review';

    /** Activate an approved membership application into a member account. */
    case ActivateMembership = 'membership.activate';

    /** View, confirm and reject payments. */
    case ReviewPayments = 'payments.review';

    /** Manage user accounts: role assignment, suspend/reactivate. Not yet consumed by any route. */
    case ManageUsers = 'users.manage';

    /** Manage membership settings, bank accounts and other system configuration. Not yet consumed by any route. */
    case ManageSettings = 'settings.manage';

    /** Read-only visibility into the audit log. Not yet consumed by any route. */
    case ViewAuditLog = 'audit.view';

    /** Read-only visibility into technical/operational diagnostics. Not yet consumed by any route. */
    case ViewDiagnostics = 'diagnostics.view';
}
