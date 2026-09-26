<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Models\Membership;
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
    public function show(Request $request): View
    {
        $membership = Membership::query()
            ->where('user_id', $request->user()->id)
            ->with(['category:id,name,code', 'terms'])
            ->first();

        abort_if($membership === null, 404);

        Gate::authorize('view', $membership);

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
