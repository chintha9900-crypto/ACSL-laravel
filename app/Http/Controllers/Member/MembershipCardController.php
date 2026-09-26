<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MembershipCardController extends Controller
{
    /**
     * The signed-in member's own digital membership card. Ownership comes only
     * from the authenticated user's id; the route accepts no membership/user
     * identifier, so there is nothing in the URL for one member to swap for
     * another's. `MembershipPolicy::view()` is the enforced backstop.
     *
     * Shows only what the approved card design allows (name, number, joined
     * date, valid-until, QR) — never email, phone, address or any internal id.
     * "Valid" here is exactly `Membership::hasCurrentTerm()` — the same signal
     * the public verification endpoint checks — so the card and its QR always
     * agree with each other.
     */
    public function show(Request $request): View
    {
        $membership = Membership::query()
            ->where('user_id', $request->user()->id)
            ->with('terms')
            ->firstOrFail();

        Gate::authorize('view', $membership);

        $currentTerm = $membership->currentTerm();
        $displayTerm = $currentTerm ?? $membership->terms->sortByDesc('term_no')->first();

        return view('member.membership.card', [
            'user' => $request->user(),
            'membership' => $membership,
            'isCurrentlyValid' => $currentTerm !== null,
            'validUntil' => $displayTerm?->expires_on,
            'verificationUrl' => route('verification.show', ['token' => $membership->verification_token]),
        ]);
    }
}
