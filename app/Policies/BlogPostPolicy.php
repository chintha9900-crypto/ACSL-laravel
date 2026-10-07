<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Blog admin actions require the `content.manage`/`content.publish`
 * permission (RBAC foundation), held by `admin` and `editor`. Public reads
 * have no auth requirement and are not covered by a policy — visibility is
 * enforced by BlogPost::scopePublished() in the public controller.
 */
class BlogPostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function view(User $user, BlogPost $post): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, BlogPost $post): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, BlogPost $post): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function publish(User $user, BlogPost $post): bool
    {
        return $user->hasPermission(Ability::PublishContent);
    }
}
