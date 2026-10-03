<?php

namespace App\Policies;

use App\Models\CommercialPartner;
use App\Models\User;

/**
 * Admin Commercial Partners CRUD — same admin-role gate as every other
 * content/catalogue policy in this app (NewsItemPolicy, EventListingPolicy,
 * CsrProjectPolicy, ProductCategoryPolicy). There is no `publish` ability
 * here, unlike the three content policies: Commercial Partners has no
 * draft/publish workflow, only a plain `is_active` toggle, so activate/
 * deactivate are gated by the ordinary `update` ability — the same choice
 * `ProductCategoryPolicy` already makes for its own `is_active` toggle.
 */
class CommercialPartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, CommercialPartner $partner): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, CommercialPartner $partner): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function delete(User $user, CommercialPartner $partner): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
