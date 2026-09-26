<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Membership\ResendAccountSetupLink;
use App\Exceptions\SetupLinkCannotBeResentException;
use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MembershipSetupLinkController extends Controller
{
    /**
     * Send a fresh one-time setup link to a member who has not finished account setup.
     * The link itself is never shown to the admin.
     */
    public function store(Request $request, MembershipApplication $application, ResendAccountSetupLink $resend): RedirectResponse
    {
        Gate::authorize('resendSetupLink', $application);

        $redirect = redirect()->route('admin.membership-applications.show', $application);
        $membership = $application->membership;

        if ($membership === null) {
            return $redirect->withErrors(['setup_link' => 'This application has not been activated.']);
        }

        try {
            $sent = $resend->handle($membership, $request->user());
        } catch (SetupLinkCannotBeResentException $exception) {
            return $redirect->withErrors(['setup_link' => $exception->getMessage()]);
        }

        if (! $sent) {
            return $redirect->with('warning', 'A new setup link was issued, but the account setup email could not be sent.');
        }

        return $redirect->with('status', 'A new account setup email has been sent. Any earlier setup link no longer works.');
    }
}
