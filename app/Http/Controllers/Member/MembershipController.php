<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\MembershipTerm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MembershipController extends Controller
{
    /**
     * The signed-in member's own membership and term history.
     *
     * Ownership comes only from the authenticated user's id; the route accepts
     * no membership/user identifier. A member with no membership sees the
     * approved empty state rather than a 404 (docs/frontend/04 §4).
     */
    public function show(Request $request): View
    {
        $membership = Membership::query()
            ->where('user_id', $request->user()->id)
            ->with(['category:id,name,description', 'terms.payment.bankAccount', 'terms.payment.evidence'])
            ->first();

        if ($membership === null) {
            return view('member.membership.show', ['membership' => null]);
        }

        Gate::authorize('view', $membership);

        // "Current" for this page is stricter than the dashboard's: the term must
        // be active AND today must fall within its own dates. The daily job that
        // flips a lapsed term to `expired` (docs/architecture/04 §9) does not exist
        // yet, so a term can sit at status=active after its own expiry date — this
        // page must not present that as a live membership. Reuses the model's own
        // definition (`Membership::currentTerm()`) instead of a second copy of it.
        $currentTerm = $membership->currentTerm();

        // Outside that window, fall back to the most recent term by term_no so the
        // page still has something meaningful to show — clearly not as "current".
        $displayTerm = $currentTerm ?? $membership->terms->sortByDesc('term_no')->first();

        $pendingRenewal = $membership->terms->firstWhere('status', MembershipTerm::STATUS_PENDING_PAYMENT);

        return view('member.membership.show', [
            'membership' => $membership,
            'displayTerm' => $displayTerm,
            'isCurrentlyValid' => $currentTerm !== null,
            'terms' => $membership->terms,
            'pendingRenewal' => $pendingRenewal,
        ]);
    }
}
