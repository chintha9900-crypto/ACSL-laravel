<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\View\View;

class VerificationController extends Controller
{
    /**
     * Public QR/card verification. No authentication: anyone who scans a card's
     * QR code reaches this page. The token is the only credential — an unknown
     * or tampered token simply matches no row and fails the same way a lapsed
     * membership does, so this can never be used to distinguish "no such token"
     * from "expired member" (rate-limited at the route).
     *
     * Validity is always `Membership::hasCurrentTerm()`, read live from the
     * database on every request — never cached, never a second validity system.
     * On success this shows only the name and membership number (never email,
     * phone, address, payment data or any internal id) and links on to the
     * configured vendor destination.
     */
    public function show(string $token): View
    {
        $membership = Membership::query()
            ->where('verification_token', $token)
            ->with(['terms', 'user:id,name'])
            ->first();

        $isValid = $membership !== null && $membership->hasCurrentTerm();

        return view('membership.verification', [
            'isValid' => $isValid,
            'name' => $isValid ? $membership->user->name : null,
            'membershipNumber' => $isValid ? $membership->membership_number : null,
            'vendorUrl' => config('membership.verification_vendor_url'),
        ]);
    }
}
