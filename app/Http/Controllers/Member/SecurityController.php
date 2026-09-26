<?php

namespace App\Http\Controllers\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SecurityController extends Controller
{
    /**
     * The signed-in member's own "change password" form. Ownership comes only
     * from the authenticated user's id; there is no user id in the route.
     */
    public function show(Request $request): View
    {
        return view('member.security.edit');
    }

    /**
     * Change the member's own password. `UpdatePasswordRequest` verifies the
     * current password and authorizes via `UserPolicy::update` before this runs.
     *
     * Only `password` and `remember_token` are ever written here — never widened
     * to any other column, and never logged, flashed or echoed back.
     */
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        // The `hashed` cast on User::password does the hashing; nothing here ever
        // sees or stores the plaintext beyond this one assignment.
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        // Rotates the session id so a fixated/observed session id from before the
        // change is no longer valid — the same call already used after login.
        $request->session()->regenerate();

        return redirect()->route('member.security.show')->with('status', 'Your password has been changed.');
    }
}
