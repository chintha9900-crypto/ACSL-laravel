<?php

namespace App\Http\Controllers;

use App\Models\CommercialPartner;
use Illuminate\View\View;

/**
 * Public Commercial Partners listing (Phase 1.4C). `is_active` is the only
 * visibility rule — no draft/publish workflow exists for this entity —
 * enforced here, server-side, the same way every other public listing in
 * this app enforces its own visibility rule (EshopController::index(),
 * CsrController::index(), etc.) rather than leaving it to the view.
 */
class CommercialPartnerController extends Controller
{
    public function show(): View
    {
        $partners = CommercialPartner::query()
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return view('commercial-partners', ['partners' => $partners]);
    }
}
