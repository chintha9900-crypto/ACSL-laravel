<?php

namespace App\Policies;

use App\Models\EducationArticle;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Admin Education management — same permissions as JobListingPolicy and
 * CsrProjectPolicy: the existing `content.manage`/`content.publish` grants,
 * held by `admin` and `editor` (admins also pass via `Gate::before`). No
 * editor-to-admin approval step. Public reads are not covered here.
 */
class EducationArticlePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function view(User $user, EducationArticle $article): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, EducationArticle $article): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, EducationArticle $article): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function publish(User $user, EducationArticle $article): bool
    {
        return $user->hasPermission(Ability::PublishContent);
    }
}
