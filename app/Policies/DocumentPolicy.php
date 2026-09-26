<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Private documents may always be opened by an active admin. A member may also
 * open a document they uploaded themselves when its `visibility` says so — the
 * column the schema already carries for exactly this (docs/database/07 §3) —
 * which today means their own payment evidence (aviation proof has no uploader
 * account at submission time, so this never applies to it in practice).
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($user->role === 'admin' && $user->status === 'active') {
            return true;
        }

        return $document->visibility === Document::VISIBILITY_OWNER_AND_ADMIN
            && $document->uploaded_by_user_id === $user->id;
    }
}
