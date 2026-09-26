<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Membership\ActivateMembership;
use App\Actions\Membership\DeliverAccountSetupLink;
use App\Exceptions\MembershipCannotBeActivatedException;
use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MembershipActivationController extends Controller
{
    /**
     * Activate an approved application: an explicit admin action that must be confirmed.
     * A stale page or a repeated submission finds the membership already exists.
     *
     * The setup email is sent only after the activation has committed; if it cannot be
     * sent the membership stays activated and the admin is told to use "Resend".
     */
    public function store(Request $request, MembershipApplication $application, ActivateMembership $activate, DeliverAccountSetupLink $deliver): RedirectResponse
    {
        Gate::authorize('activate', $application);

        $request->validate(['confirm' => ['accepted']], [
            'confirm.accepted' => 'Tick the box to confirm the activation.',
        ]);

        try {
            $activated = $activate->handle($application, $request->user());
        } catch (MembershipCannotBeActivatedException $exception) {
            return redirect()
                ->route('admin.membership-applications.show', $application)
                ->withErrors(['activation' => $exception->getMessage()]);
        }

        $membership = $activated->membership;
        $sent = $deliver->handle($membership->user, $activated->setupToken);

        $redirect = redirect()->route('admin.membership-applications.show', $application);

        if (! $sent) {
            return $redirect->with('warning', 'Membership activated, but the account setup email could not be sent.');
        }

        return $redirect->with('status', 'Membership activated. Membership number '.$membership->membership_number.'. The account setup email has been sent.');
    }
}
