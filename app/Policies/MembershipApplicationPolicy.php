<?php

namespace App\Policies;

use App\Models\MembershipApplication;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Applications are visible to, and reviewable by, whoever holds the
 * `membership.applications.review` permission (RBAC foundation) — `admin`
 * and `editor`. Activation is its own, separate `membership.activate`
 * permission (also held by `admin` and `editor`), kept distinct so it can be
 * withheld independently of plain review if that's ever needed. Enforced on
 * the server for every request.
 */
class MembershipApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications);
    }

    public function view(User $user, MembershipApplication $application): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications);
    }

    public function review(User $user, MembershipApplication $application): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications);
    }

    public function activate(User $user, MembershipApplication $application): bool
    {
        return $user->hasPermission(Ability::ActivateMembership);
    }

    public function resendSetupLink(User $user, MembershipApplication $application): bool
    {
        return $user->hasPermission(Ability::ReviewMembershipApplications);
    }
}
