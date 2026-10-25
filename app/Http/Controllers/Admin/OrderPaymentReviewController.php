<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Payments\ReviewOrderBankTransfer;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectPaymentRequest;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Admin verification of e-shop bank transfers — separate from membership
 * payment review (PaymentReviewController), so an order payment can never
 * reach renewal confirmation. Gated by the same `payments.review` permission.
 */
class OrderPaymentReviewController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Payment::class);

        $payments = Payment::query()
            ->whereNotNull('order_id')
            ->where('gateway', Payment::GATEWAY_MANUAL_BANK_TRANSFER)
            ->where('status', Payment::STATUS_PROCESSING)
            ->with(['order:id,public_id,order_number,customer_name,customer_email,total_amount,currency,status,created_at,expires_at', 'evidence'])
            ->orderBy('submitted_at')
            ->paginate(15);

        return view('admin.order-payments.index', ['payments' => $payments]);
    }

    public function approve(Request $request, Payment $payment, ReviewOrderBankTransfer $review): RedirectResponse
    {
        Gate::authorize('review', $payment);

        try {
            $outcome = $review->approve($payment, $request->user());
        } catch (PaymentCannotBeReviewedException $exception) {
            return redirect()->route('admin.order-payments.index')->withErrors(['payment' => $exception->getMessage()]);
        }

        return match ($outcome) {
            ReviewOrderBankTransfer::SENT_TO_REVIEW => redirect()->route('admin.order-payments.index')
                ->withErrors(['payment' => 'This order is no longer awaiting payment, so the transfer was not approved. It has been placed in the payment review queue.']),
            ReviewOrderBankTransfer::ALREADY_APPROVED => redirect()->route('admin.order-payments.index')->with('status', 'This payment was already verified.'),
            default => redirect()->route('admin.order-payments.index')->with('status', 'Payment verified. The order is now paid and the receipt has been sent.'),
        };
    }

    public function reject(RejectPaymentRequest $request, Payment $payment, ReviewOrderBankTransfer $review): RedirectResponse
    {
        try {
            $review->reject($payment, $request->user(), $request->validated('reason'));
        } catch (PaymentCannotBeReviewedException $exception) {
            return redirect()->route('admin.order-payments.index')->withErrors(['payment' => $exception->getMessage()]);
        }

        return redirect()->route('admin.order-payments.index')->with('status', 'Payment rejected. The customer has been notified and can resubmit.');
    }
}
