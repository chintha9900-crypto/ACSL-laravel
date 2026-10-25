<?php

namespace App\Http\Controllers;

use App\Actions\Payments\StartOrderPaymentAttempt;
use App\Actions\Payments\SubmitOrderBankTransfer;
use App\Exceptions\PaymentCannotBeSubmittedException;
use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Http\Requests\SubmitOrderBankTransferRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\AvailablePaymentMethods;
use App\Payments\DummyPaymentGate;
use App\Payments\PayHere;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * The customer's payment page for one order, reached by its owner (or, for a
 * guest order, a signed link — AuthorizesOrderAccess). Nothing here can mark
 * a payment paid: bank transfers wait for admin verification, PayHere for
 * its verified notify callback (PayHereNotificationController), and the
 * dummy method for DummyPaymentController. The PayHere return and cancel
 * links only change what the page says.
 */
class PaymentController extends Controller
{
    use AuthorizesOrderAccess;

    /**
     * Query parameters PayHere may append to the return/cancel links.
     */
    private const PAYHERE_RETURN_QUERY = ['order_id', 'payment_id', 'status_code', 'md5sig'];

    public function __construct(
        private readonly PayHere $payHere,
        private readonly DummyPaymentGate $dummyGate,
        private readonly AvailablePaymentMethods $paymentMethods,
    ) {}

    public function show(Request $request, Order $order): View
    {
        $this->authorizeOrderAccess($request, $order);

        $order->load(['items', 'payments.evidence']);
        $payment = $order->payments->sortByDesc('id')->first();
        $awaitingPayment = $order->isPayable();
        $open = $awaitingPayment && $payment !== null && in_array($payment->status, [Payment::STATUS_PENDING, Payment::STATUS_FAILED, Payment::STATUS_CANCELLED], true);

        return view('payment.show', [
            'order' => $order,
            'payment' => $payment,
            'awaitingPayment' => $awaitingPayment,
            // Evidence under review is never shown as expired: an admin decides.
            'deadlinePassed' => $order->status === Order::STATUS_EXPIRED
                || ($order->status === Order::STATUS_PENDING_PAYMENT && ! $awaitingPayment && $payment?->status !== Payment::STATUS_PROCESSING),
            'bankAccount' => $payment?->gateway === Payment::GATEWAY_MANUAL_BANK_TRANSFER ? $payment->currentBankAccount() : null,
            'payHereForm' => $awaitingPayment ? $this->payHereForm($order, $payment) : null,
            // Only from this browser's own redirect back from PayHere (session
            // flash) — a hint for the customer, never stored, never trusted.
            'awaitingPayHereConfirmation' => $payment?->gateway === Payment::GATEWAY_PAYHERE
                && $payment->status === Payment::STATUS_PENDING
                && $request->session()->get('payhere_returned') === true,
            'dummyAvailable' => $awaitingPayment && $order->is_test && $payment?->gateway === Payment::GATEWAY_DUMMY
                && $payment->status === Payment::STATUS_PENDING && $this->dummyGate->allows($request),
            'retryMethods' => $open ? $this->retryMethods($request, $order, $payment) : [],
            'urls' => [
                'retry' => $this->orderRouteUrl($order, 'payment.retry'),
                'bankTransfer' => $this->orderRouteUrl($order, 'payment.bank-transfer'),
            ],
            'receiptUrl' => $order->hasReceipt() ? $this->orderRouteUrl($order, 'orders.receipt') : null,
        ]);
    }

    /**
     * A new attempt after a failed/cancelled one, or a switch of method.
     */
    public function retry(Request $request, Order $order, StartOrderPaymentAttempt $startAttempt): RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $methods = $order->is_test ? [Order::METHOD_DUMMY] : array_values(array_diff($this->paymentMethods->for($request, $order->currency), [Order::METHOD_DUMMY]));
        $validated = $request->validate(['payment_method' => ['required', 'string', Rule::in($methods)]]);

        if ($validated['payment_method'] === Order::METHOD_PAYHERE && ! $this->hasPayHerePhone($order)) {
            return redirect()->to($this->orderRouteUrl($order, 'payment.show'))
                ->withErrors(['payment' => 'PayHere needs a phone number on the order. Please pay by bank transfer, or place a new order with your phone number.']);
        }

        try {
            $startAttempt->handle($order, $validated['payment_method'], $request->user());
        } catch (PaymentCannotBeSubmittedException $e) {
            return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->with('status', 'You can now complete your payment.');
    }

    public function submitBankTransfer(SubmitOrderBankTransferRequest $request, Order $order, SubmitOrderBankTransfer $submit): RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order);

        $payment = $order->latestPayment();

        try {
            if ($payment === null) {
                throw new PaymentCannotBeSubmittedException('This payment could not be found.');
            }

            $submit->handle($payment, $request->user(), $request->validated('reference'), $request->evidenceFiles());
        } catch (PaymentCannotBeSubmittedException $e) {
            return redirect()->to($this->orderRouteUrl($order, 'payment.show'))->withErrors(['payment' => $e->getMessage()]);
        } catch (RuntimeException) {
            return redirect()->to($this->orderRouteUrl($order, 'payment.show'))
                ->withErrors(['evidence' => 'Your evidence could not be uploaded. Please try again.']);
        }

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))
            ->with('status', 'Thank you — your transfer details were received. We will confirm your order once the payment is verified.');
    }

    /**
     * The customer is back from PayHere. Never treated as proof of payment and
     * changes nothing stored: only the verified notify callback confirms. A
     * session flash lets the page warn against paying twice while the Pay
     * button stays available in case the payment was not completed.
     */
    public function payHereReturn(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order, self::PAYHERE_RETURN_QUERY);

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))
            ->with('payhere_returned', true)
            ->with('status', 'Thank you. We are waiting for PayHere to confirm your payment — this page will show it once confirmed.');
    }

    public function payHereCancel(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOrderAccess($request, $order, self::PAYHERE_RETURN_QUERY);

        return redirect()->to($this->orderRouteUrl($order, 'payment.show'))
            ->with('status', 'The PayHere payment was not completed. You can try again below.');
    }

    /**
     * @return array{action: string, fields: array<string, string>}|null
     */
    private function payHereForm(Order $order, ?Payment $payment): ?array
    {
        if (! $order->isPayable()
            || $payment?->gateway !== Payment::GATEWAY_PAYHERE
            || $payment->status !== Payment::STATUS_PENDING
            || ! $this->payHere->isConfigured()
            || ! $this->hasPayHerePhone($order)) {
            return null;
        }

        $returnUrl = $this->orderRouteUrl($order, 'payment.payhere.return');
        $cancelUrl = $this->orderRouteUrl($order, 'payment.payhere.cancel');
        $notifyUrl = route('payments.payhere.notify');

        // PayHere must reach the notify URL over public HTTPS. If the server's
        // proxy/URL configuration produces http links anywhere but local
        // development, offer no PayHere form rather than a broken checkout.
        if (! in_array(config('app.env'), ['local', 'testing'], true)
            && ! collect([$returnUrl, $cancelUrl, $notifyUrl])->every(fn (string $url): bool => str_starts_with($url, 'https://'))) {
            Log::warning('PayHere checkout withheld: generated callback URLs are not HTTPS. Check APP_URL / proxy configuration.');

            return null;
        }

        return [
            'action' => $this->payHere->checkoutUrl(),
            'fields' => $this->payHere->checkoutFields($order, $payment, $returnUrl, $cancelUrl, $notifyUrl),
        ];
    }

    /**
     * @return list<string>
     */
    private function retryMethods(Request $request, Order $order, Payment $payment): array
    {
        if ($order->is_test) {
            return $payment->status === Payment::STATUS_PENDING ? [] : [Order::METHOD_DUMMY];
        }

        $current = array_search($payment->gateway, Order::METHOD_GATEWAYS, true);
        // The current method is not offered again while its attempt is open,
        // nor after a rejected bank transfer (resubmitted on the same attempt).
        $currentStillUsable = $payment->status === Payment::STATUS_PENDING
            || ($current === Order::METHOD_BANK_TRANSFER && $payment->status === Payment::STATUS_FAILED);

        return array_values(array_filter(
            $this->paymentMethods->for($request, $order->currency),
            fn (string $method): bool => $method !== Order::METHOD_DUMMY
                && ! ($currentStillUsable && $method === $current)
                && $order->paymentDeadlineFor($method)->isFuture(),
        ));
    }

    private function hasPayHerePhone(Order $order): bool
    {
        return is_string($order->customer_phone) && preg_match('/^\+[1-9][0-9]{6,14}$/', $order->customer_phone) === 1;
    }
}
