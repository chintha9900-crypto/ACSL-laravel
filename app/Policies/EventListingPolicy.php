<?php

namespace App\Policies;

use App\Models\EventListing;
use App\Models\User;

/**
 * Admin Events CRUD — mirrors NewsItemPolicy/BlogPostPolicy exactly: admin
 * actions require the admin role. Public reads have no auth requirement and
 * are not covered by a policy — visibility is enforced by
 * EventListing::scopePublished() in the public controller.
 */
class EventListingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, EventListing $event): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, EventListing $event): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, EventListing $event): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function publish(User $user, EventListing $event): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
