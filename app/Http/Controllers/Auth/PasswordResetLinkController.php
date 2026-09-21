<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the "forgot password" form.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Send a reset link to an existing active user.
     *
     * Only `active` users are eligible: a `pending_setup` account has no
     * password to reset and must use its setup link. The response is the same
     * whether or not an account matched, so it cannot be used to probe emails.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink([
            'email' => $validated['email'],
            'status' => 'active',
        ]);

        return back()->with('status', 'If an account exists for that email, a password reset link has been sent.');
    }
}
