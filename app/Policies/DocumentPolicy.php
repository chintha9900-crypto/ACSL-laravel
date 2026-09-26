<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Private documents (aviation proof) may be opened by active admins only.
 * Applicant/owner access is not part of this phase (docs/architecture/07 §2).
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
