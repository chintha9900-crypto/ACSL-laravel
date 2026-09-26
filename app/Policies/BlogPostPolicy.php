<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\User;

/**
 * Blog admin actions require the admin role (docs/architecture/10 §9), same
 * pattern as MembershipApplicationPolicy. Public reads have no auth
 * requirement and are not covered by a policy — visibility is enforced by
 * BlogPost::scopePublished() in the public controller.
 */
class BlogPostPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, BlogPost $post): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, BlogPost $post): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, BlogPost $post): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function publish(User $user, BlogPost $post): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
