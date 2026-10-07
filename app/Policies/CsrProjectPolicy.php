<?php

namespace App\Policies;

use App\Models\CsrProject;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Admin CSR CRUD — mirrors NewsItemPolicy/EventListingPolicy/BlogPostPolicy
 * exactly: requires the `content.manage`/`content.publish` permission (RBAC
 * foundation), held by `admin` and `editor`. Public reads have no auth
 * requirement and are not covered by a policy — visibility is enforced by
 * CsrProject::scopePublished() in the public controller.
 */
class CsrProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function view(User $user, CsrProject $project): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, CsrProject $project): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, CsrProject $project): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function publish(User $user, CsrProject $project): bool
    {
        return $user->hasPermission(Ability::PublishContent);
    }
}
