<?php

namespace App\Policies;

use App\Models\MembershipApplication;
use App\Models\User;

/**
 * Applications are visible to, and reviewable by, active admins only
 * (docs/architecture/05 §2). Enforced on the server for every request.
 */
class MembershipApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, MembershipApplication $application): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function review(User $user, MembershipApplication $application): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function activate(User $user, MembershipApplication $application): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function resendSetupLink(User $user, MembershipApplication $application): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
