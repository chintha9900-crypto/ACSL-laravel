<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompleteAccountSetup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AccountSetupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AccountSetupController extends Controller
{
    /**
     * Show the "create your password" form for a valid setup link.
     *
     * Every failure (unknown, expired, used, invalidated) renders the same
     * generic page so the reason is never disclosed.
     */
    public function show(string $token, CompleteAccountSetup $setup): View|Response
    {
        if (! $setup->isUsable($token)) {
            return $this->invalidLink();
        }

        return $this->sealed(response()->view('auth.account-setup', ['token' => $token]));
    }

    /**
     * Set the first password, activate the account and consume the token.
     */
    public function store(AccountSetupRequest $request, string $token, CompleteAccountSetup $setup): RedirectResponse|Response
    {
        if (! $setup->handle($token, $request->validated('password'))) {
            return $this->invalidLink();
        }

        return redirect()->route('login')->with('status', 'Your password has been created. You can now sign in.');
    }

    /**
     * The one generic response for every unusable link.
     */
    private function invalidLink(): Response
    {
        return $this->sealed(response()->view('auth.setup-invalid', [], 404));
    }

    /**
     * Keep the token out of caches and Referer headers.
     */
    private function sealed(Response $response): Response
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
