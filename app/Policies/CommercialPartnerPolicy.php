<?php

namespace App\Policies;

use App\Models\CommercialPartner;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Admin Commercial Partners CRUD — requires the `content.manage` permission
 * (RBAC foundation), held by `admin` and `editor`. There is no `publish`
 * ability here, unlike the three content policies: Commercial Partners has
 * no draft/publish workflow, only a plain `is_active` toggle, so activate/
 * deactivate are gated by the ordinary `update` ability — the same choice
 * `ProductCategoryPolicy` already makes for its own `is_active` toggle.
 */
class CommercialPartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function view(User $user, CommercialPartner $partner): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, CommercialPartner $partner): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, CommercialPartner $partner): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }
}
