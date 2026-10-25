<?php

namespace App\Policies;

use App\Models\BlogComment;
use App\Models\User;

/**
 * Blog comments: only an active member may post; moderation (listing pending
 * comments, approving, rejecting) is admin-only — no existing permission
 * grants it to editor or dev, so every non-admin is denied here and an
 * active admin passes through the global `Gate::before`.
 */
class BlogCommentPolicy
{
    public function create(User $user): bool
    {
        return $user->role === 'member' && $user->status === 'active';
    }

    public function moderate(User $user, ?BlogComment $comment = null): bool
    {
        return false;
    }
}
