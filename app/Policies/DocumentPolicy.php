<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Private documents may always be opened by an admin (via the blanket
 * `Gate::before` bypass). An `editor`/`dev` gets no general access to
 * private documents — only the specific, narrow case the approved RBAC
 * design calls out: a holder of `membership.applications.review` may open
 * an aviation-proof document (it's what that review is of), and a holder of
 * `payments.review` may open a payment-evidence document, for the same
 * reason. Any other document `kind` (e.g. `job_application_document`, which
 * has no reviewing permission of its own yet) is not covered by either
 * branch and fails closed.
 *
 * A member may also open a document they uploaded themselves when its
 * `visibility` says so — the column the schema already carries for exactly
 * this (docs/database/07 §3) — which today means their own payment evidence
 * (aviation proof has no uploader account at submission time, so this never
 * applies to it in practice). This ownership check is unchanged by the RBAC
 * foundation.
 */
class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        if ($document->kind === Document::KIND_AVIATION_PROOF && $user->hasPermission(Ability::ReviewMembershipApplications)) {
            return true;
        }

        if ($document->kind === Document::KIND_PAYMENT_EVIDENCE && $user->hasPermission(Ability::ReviewPayments)) {
            return true;
        }

        return $document->visibility === Document::VISIBILITY_OWNER_AND_ADMIN
            && $document->uploaded_by_user_id === $user->id;
    }
}
