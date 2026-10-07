<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Authorization\RoleRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the sign-in form.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Sign a user in. The failure message is deliberately identical for an
     * unknown email, a wrong password, a pending or suspended account.
     *
     * The post-login landing page is decided purely from `Auth::user()->role`,
     * read fresh from the database row behind the new session — never from
     * anything in `$request`'s own input (a `role` field in the submitted
     * form, a query parameter, a cookie, etc. has no effect either way).
     * `intended()` still takes priority when the visitor was redirected here
     * from a specific protected page.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::attempt($request->credentials(), $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(RoleRedirect::homeRouteFor(Auth::user()));
    }

    /**
     * Sign the user out and discard the session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
