<?php

namespace App\Policies;

use App\Models\NewsItem;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Admin News CRUD — mirrors BlogPostPolicy exactly: requires the
 * `content.manage`/`content.publish` permission (RBAC foundation), held by
 * `admin` and `editor`. Public reads have no auth requirement and are not
 * covered by a policy — visibility is enforced by NewsItem::scopePublished()
 * in the public controller.
 */
class NewsItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function view(User $user, NewsItem $news): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, NewsItem $news): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, NewsItem $news): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function publish(User $user, NewsItem $news): bool
    {
        return $user->hasPermission(Ability::PublishContent);
    }
}
