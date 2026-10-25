<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Payments\ResolvePaymentReview;
use App\Http\Controllers\Controller;
use App\Models\PaymentReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The manual review queue for e-shop payment conflicts (duplicates,
 * chargebacks, late payments, amount mismatches, transfer conflicts).
 */
class PaymentReviewQueueController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', PaymentReview::class);

        $status = $request->query('status') === PaymentReview::STATUS_RESOLVED ? PaymentReview::STATUS_RESOLVED : PaymentReview::STATUS_OPEN;

        return view('admin.payment-reviews.index', [
            'status' => $status,
            'reviews' => PaymentReview::query()
                ->where('status', $status)
                ->with(['order:id,public_id,order_number,status,customer_name', 'payment:id,public_id,idempotency_key,gateway,status,amount,currency,transaction_reference', 'resolvedBy:id,name'])
                ->orderBy($status === PaymentReview::STATUS_OPEN ? 'created_at' : 'resolved_at', $status === PaymentReview::STATUS_OPEN ? 'asc' : 'desc')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function resolve(Request $request, PaymentReview $paymentReview, ResolvePaymentReview $resolve): RedirectResponse
    {
        Gate::authorize('update', $paymentReview);

        $validated = $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);

        $resolve->handle($paymentReview, $request->user(), $validated['resolution_note']);

        return redirect()->route('admin.payment-reviews.index')->with('status', 'Review item resolved.');
    }
}
