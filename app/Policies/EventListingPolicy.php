<?php

namespace App\Policies;

use App\Models\EventListing;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Admin Events CRUD — mirrors NewsItemPolicy/BlogPostPolicy exactly:
 * requires the `content.manage`/`content.publish` permission (RBAC
 * foundation), held by `admin` and `editor`. Public reads have no auth
 * requirement and are not covered by a policy — visibility is enforced by
 * EventListing::scopePublished() in the public controller.
 */
class EventListingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function view(User $user, EventListing $event): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, EventListing $event): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, EventListing $event): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function publish(User $user, EventListing $event): bool
    {
        return $user->hasPermission(Ability::PublishContent);
    }
}
