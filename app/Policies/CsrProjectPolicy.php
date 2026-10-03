<?php

namespace App\Policies;

use App\Models\CsrProject;
use App\Models\User;

/**
 * Admin CSR CRUD — mirrors NewsItemPolicy/EventListingPolicy/BlogPostPolicy
 * exactly: admin actions require the admin role. Public reads have no auth
 * requirement and are not covered by a policy — visibility is enforced by
 * CsrProject::scopePublished() in the public controller.
 */
class CsrProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, CsrProject $project): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, CsrProject $project): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, CsrProject $project): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function publish(User $user, CsrProject $project): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
