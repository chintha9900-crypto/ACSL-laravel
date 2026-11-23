<?php

namespace App\Policies;

use App\Models\JobListing;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Admin Jobs management — same permissions as CsrProjectPolicy: the existing
 * `content.manage`/`content.publish` grants, held by `admin` and `editor`
 * (admins also pass via the `Gate::before` bypass). There is no editor-to-
 * admin approval step. Public reads are not covered here; visibility is
 * JobListing::scopePubliclyVisible().
 */
class JobListingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function view(User $user, JobListing $job): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, JobListing $job): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, JobListing $job): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function publish(User $user, JobListing $job): bool
    {
        return $user->hasPermission(Ability::PublishContent);
    }
}
