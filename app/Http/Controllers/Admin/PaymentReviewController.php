<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Membership\DeliverMemberNotification;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\RejectPayment;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RejectPaymentRequest;
use App\Models\Payment;
use App\Notifications\Payments\ConfirmationRejected;
use App\Notifications\Payments\PaymentConfirmed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PaymentReviewController extends Controller
{
    /**
     * The queue of renewal payments awaiting confirmation
     * (docs/frontend/05 §"Payments awaiting confirmation").
     */
    public function index(): View
    {
        Gate::authorize('viewAny', Payment::class);

        $payments = Payment::query()
            ->where('status', Payment::STATUS_PROCESSING)
            ->with(['user:id,name,email', 'membershipTerm.membership:id,membership_number', 'evidence'])
            ->orderBy('submitted_at')
            ->paginate(15);

        return view('admin.payments.index', ['payments' => $payments]);
    }

    public function confirm(Request $request, Payment $payment, ConfirmPayment $confirm, DeliverMemberNotification $deliver): RedirectResponse
    {
        Gate::authorize('review', $payment);

        try {
            $term = $confirm->handle($payment, $request->user());
        } catch (PaymentCannotBeReviewedException $exception) {
            return redirect()->route('admin.payments.index')->withErrors(['payment' => $exception->getMessage()]);
        }

        $deliver->handle($payment->user, new PaymentConfirmed($term->load('membership')));

        return redirect()->route('admin.payments.index')->with('status', 'Payment confirmed. The renewal term is now active.');
    }

    public function reject(RejectPaymentRequest $request, Payment $payment, RejectPayment $reject, DeliverMemberNotification $deliver): RedirectResponse
    {
        try {
            $reject->handle($payment, $request->user(), $request->validated('reason'));
        } catch (PaymentCannotBeReviewedException $exception) {
            return redirect()->route('admin.payments.index')->withErrors(['payment' => $exception->getMessage()]);
        }

        $deliver->handle($payment->user, new ConfirmationRejected($payment, $request->validated('reason')));

        return redirect()->route('admin.payments.index')->with('status', 'Payment rejected. The member has been notified.');
    }
}
