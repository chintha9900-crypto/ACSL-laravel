<?php

namespace App\Policies;

use App\Models\NewsItem;
use App\Models\User;

/**
 * Admin News CRUD — mirrors BlogPostPolicy exactly: admin actions require
 * the admin role, same pattern as every other content policy. Public reads
 * have no auth requirement and are not covered by a policy — visibility is
 * enforced by NewsItem::scopePublished() in the public controller.
 */
class NewsItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, NewsItem $news): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, NewsItem $news): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, NewsItem $news): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function publish(User $user, NewsItem $news): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
