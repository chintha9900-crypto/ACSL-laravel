<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Auth\InviteEditor;
use App\Actions\Membership\DeliverAccountSetupLink;
use App\Exceptions\EditorInvitationNotAllowedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteEditorRequest;
use Illuminate\Http\RedirectResponse;

class EditorInvitationController extends Controller
{
    /**
     * Invite an editor by email. The one-time setup link is emailed only after
     * the invitation has committed; a delivery failure keeps the invitation and
     * tells the admin to invite the same address again (which reissues it).
     */
    public function store(InviteEditorRequest $request, InviteEditor $invite, DeliverAccountSetupLink $deliver): RedirectResponse
    {
        try {
            $invitation = $invite->handle($request->validated('email'), $request->user());
        } catch (EditorInvitationNotAllowedException $exception) {
            return redirect()->route('admin.dashboard')
                ->withErrors(['email' => $exception->getMessage()], 'invitation')
                ->withInput($request->only('email'));
        }

        $email = $invitation['user']->email;
        $redirect = redirect()->route('admin.dashboard');

        if (! $deliver->handle($invitation['user'], $invitation['token'])) {
            return $redirect->with('warning', "The editor invitation for {$email} was created, but the email could not be sent. Invite the same address again to resend it.");
        }

        return $redirect->with('status', $invitation['reinvited']
            ? "A new editor invitation link has been sent to {$email}. The previous link no longer works."
            : "An editor invitation has been sent to {$email}.");
    }
}
