<?php

namespace App\Http\Controllers;

use App\Actions\Payments\CancelOrderPayment;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Actions\Payments\FailOrderPayment;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\DummyPaymentGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Dummy / Test Payment — Development Only". A 404 unless DummyPaymentGate
 * allows this request; acts only on the open dummy attempt of a test order
 * (`is_test`), so it can never touch a real order or a PayHere / bank
 * transfer payment. Success uses the same ConfirmOrderPayment transition as
 * every other method.
 */
class DummyPaymentController extends Controller
{
    use AuthorizesOrderAccess;

    public function __invoke(
        Request $request,
        Order $order,
        string $outcome,
        DummyPaymentGate $gate,
        ConfirmOrderPayment $confirm,
        FailOrderPayment $fail,
        CancelOrderPayment $cancel,
    ): RedirectResponse {
        if (! $gate->allows($request)) {
            abort(404);
        }

        $this->authorizeOrderAccess($request, $order);

        $payment = $order->latestPayment();

        if (! $order->is_test || $payment?->gateway !== Payment::GATEWAY_DUMMY || $payment->status !== Payment::STATUS_PENDING) {
            return redirect()->to($this->orderRouteUrl($order, 'payment.show'))
                ->withErrors(['payment' => 'There is no open test payment for this order.']);
        }

        $simulated = ['metadata' => ['simulated' => true, 'outcome' => $outcome]];

        try {
            match ($outcome) {
                'success' => $confirm->handle($payment, $request->user(), $simulated, [Payment::STATUS_PENDING], strict: true),
                'failure' => $fail->handle($payment, 'Simulated failure (development test payment).', $request->user(), $simulated),
                'cancel' => $cancel->handle($payment, $request->user(), $simulated),
            };
        } catch (PaymentCannotBeReviewedException $e) {
            return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->with('status', match ($outcome) {
            'success' => 'Test payment simulated as successful.',
            'failure' => 'Test payment simulated as failed.',
            'cancel' => 'Test payment simulated as cancelled.',
        });
    }
}
