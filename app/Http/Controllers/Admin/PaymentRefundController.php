<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Payments\RecordManualRefund;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Support\Money;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Records a refund already made manually in the PayHere merchant portal (or
 * by bank). There is no automated refund call.
 */
class PaymentRefundController extends Controller
{
    public function store(Request $request, Payment $payment, RecordManualRefund $recordRefund): RedirectResponse
    {
        Gate::authorize('recordRefund', $payment);

        $validated = $request->validate([
            'amount' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! Money::isValid($value)) {
                    $fail('Enter the refunded amount as a number with up to two decimals.');
                }
            }],
            'reason' => ['required', 'string', 'max:500'],
            'refund_reference' => ['required', 'string', 'max:191'],
            'refunded_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $back = $payment->order_id === null
            ? redirect()->route('admin.payment-reviews.index')
            : redirect()->route('admin.orders.show', $payment->order()->value('public_id'));

        try {
            $recordRefund->handle($payment, $request->user(), $validated['amount'], $validated['reason'], $validated['refund_reference'], Carbon::parse($validated['refunded_on']));
        } catch (PaymentCannotBeReviewedException $e) {
            return $back->withErrors(['refund' => $e->getMessage()])->withInput();
        }

        return $back->with('status', 'Refund recorded.');
    }
}
