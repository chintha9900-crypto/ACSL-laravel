<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * The signed-in member's own dashboard.
     *
     * Ownership comes only from the authenticated user's id (`auth()->id()`); the
     * route accepts no membership/user identifier, so there is nothing in the URL
     * for one member to swap for another's. The policy check is a backstop, not
     * the ownership mechanism itself.
     */
    public function show(Request $request): View|RedirectResponse
    {
        $membership = Membership::query()
            ->where('user_id', $request->user()->id)
            ->with(['category:id,name,code', 'terms'])
            ->first();

        abort_if($membership === null, 404);

        Gate::authorize('view', $membership);

        // A lapsed membership (expired, no current term) is funnelled straight to
        // the membership/renewal page instead of the normal benefits dashboard —
        // the approved M11 rule ("membership benefits are unavailable" / "member
        // cannot log in" to the ordinary member experience) — while still leaving
        // the account itself, and the renewal flow, fully reachable: this is not a
        // real "cannot authenticate" block, since that would also block the one
        // page a lapsed member needs to renew during the grace period.
        if (! $membership->hasCurrentTerm()) {
            return redirect()
                ->route('member.membership.show')
                ->with('warning', 'Your membership has expired. Renew now to restore your membership benefits.');
        }

        // Prefer the term that is currently active; a lapsed membership between
        // terms falls back to the most recent one by term number.
        $currentTerm = $membership->terms->firstWhere('status', 'active')
            ?? $membership->terms->sortByDesc('term_no')->first();

        return view('member.dashboard', [
            'user' => $request->user(),
            'membership' => $membership,
            'currentTerm' => $currentTerm,
        ]);
    }
}
