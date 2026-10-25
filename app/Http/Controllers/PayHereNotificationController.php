<?php

namespace App\Http\Controllers;

use App\Actions\Orders\RecordOrderAudit;
use App\Actions\Payments\CancelOrderPayment;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Actions\Payments\FailOrderPayment;
use App\Actions\Payments\FlagPaymentForReview;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Payments\PayHere;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PayHere's server-to-server notify callback — the only thing that can mark
 * a PayHere payment paid. Public and CSRF-exempt (and exempt from input
 * trimming, so the md5sig is checked against the fields exactly as sent).
 *
 * Order of checks: merchant id and md5sig over the raw fields; the order id
 * must name a PayHere e-shop payment attempt; then, under the order lock
 * (order → payment), the amount must equal the stored amount exactly and the
 * currency must match. Only signed status 2 confirms. The whole decision
 * runs in one transaction that retries on deadlock, so concurrent duplicate
 * callbacks serialise and are idempotent.
 *
 * PayHere does not sign payment_id, method or status_message. They are kept
 * only as "unverified" data on the first state change and in review records;
 * they never confirm anything, never overwrite a recorded reference, and a
 * differing payment_id on its own only raises a review item.
 * Logs carry the order id and reason only — never the secret or signature.
 */
class PayHereNotificationController extends Controller
{
    private const MISMATCH = 'mismatch';

    private const HANDLED = 'handled';

    public function __invoke(
        Request $request,
        PayHere $payHere,
        ConfirmOrderPayment $confirm,
        FailOrderPayment $fail,
        CancelOrderPayment $cancel,
        FlagPaymentForReview $flag,
        RecordOrderAudit $audit,
    ): Response {
        if (! $payHere->isConfigured()) {
            abort(404);
        }

        $params = $request->only(['merchant_id', 'order_id', 'payment_id', 'payhere_amount', 'payhere_currency', 'status_code', 'md5sig', 'method', 'status_message']);
        $orderId = is_string($params['order_id'] ?? null) ? Str::limit($params['order_id'], 100, '') : null;

        if (! $payHere->hasValidSignature($params)) {
            Log::warning('PayHere notification rejected: invalid merchant or signature.', ['order_id' => $orderId]);

            return response('Invalid signature', 400);
        }

        $payment = Payment::query()
            ->where('gateway', Payment::GATEWAY_PAYHERE)
            ->where('idempotency_key', $params['order_id'])
            ->whereNotNull('order_id')
            ->first();

        if ($payment === null) {
            Log::warning('PayHere notification rejected: unknown order.', ['order_id' => $orderId]);

            return response('Unknown order', 404);
        }

        $unverified = array_filter([
            'payment_id' => $this->unsigned($params, 'payment_id', 191),
            'method' => $this->unsigned($params, 'method', 30),
            'status_message' => $this->unsigned($params, 'status_message', 255),
        ], fn (?string $value): bool => $value !== null);

        $outcome = DB::transaction(function () use ($payment, $params, $unverified, $confirm, $fail, $cancel, $flag, $audit): string {
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $signed = [
                'status_code' => $params['status_code'],
                'payhere_amount' => $params['payhere_amount'],
                'payhere_currency' => $params['payhere_currency'],
            ];

            if (! PayHere::amountMatches($params['payhere_amount'], (string) $locked->amount) || $params['payhere_currency'] !== $locked->currency) {
                $flag->handle(
                    $order,
                    $locked,
                    PaymentReview::TYPE_AMOUNT_MISMATCH,
                    "A signed PayHere notification for {$locked->idempotency_key} reported {$params['payhere_currency']} {$params['payhere_amount']}, but {$locked->currency} {$locked->amount} was expected. Nothing was changed.",
                    $signed + ['expected_amount' => $locked->amount, 'expected_currency' => $locked->currency],
                    array_values($signed),
                    $unverified['payment_id'] ?? null,
                );

                return self::MISMATCH;
            }

            $gatewayData = ['payhere' => [
                'status_code' => $params['status_code'],
                'notified_at' => now()->toIso8601String(),
                'unverified' => $unverified,
            ]];

            match ($params['status_code']) {
                PayHere::STATUS_SUCCESS => $this->succeeded($order, $locked, $unverified, $gatewayData, $signed, $confirm, $flag, $audit),
                PayHere::STATUS_FAILED => $fail->handle($locked, 'PayHere reported the payment failed.', null, ['metadata' => $gatewayData], RecordOrderAudit::ACTOR_SYSTEM),
                PayHere::STATUS_CANCELLED => $cancel->handle($locked, null, ['metadata' => $gatewayData], RecordOrderAudit::ACTOR_SYSTEM),
                PayHere::STATUS_CHARGEDBACK => $flag->handle(
                    $order,
                    $locked,
                    PaymentReview::TYPE_CHARGEBACK,
                    "PayHere reported a chargeback for {$locked->idempotency_key} (order {$order->order_number}). Review the order and record any refund.",
                    $signed + ['payment_status' => $locked->status, 'order_status' => $order->status],
                    array_values($signed),
                    $unverified['payment_id'] ?? null,
                ),
                default => $this->recordStatus($locked, $gatewayData, $audit),
            };

            return self::HANDLED;
        }, 3);

        if ($outcome === self::MISMATCH) {
            Log::warning('PayHere notification rejected: amount or currency mismatch.', ['order_id' => $orderId]);

            return response('Amount or currency mismatch', 400);
        }

        return response('OK', 200);
    }

    /**
     * A signed success. Confirms an unsettled attempt. For an attempt already
     * settled, an exact repeat (same recorded reference) is a no-op; anything
     * else is put in review — it may be a second charge or a replay with an
     * altered unsigned payment_id, which only the merchant portal can tell.
     *
     * @param  array<string, string>  $unverified
     * @param  array<string, mixed>  $gatewayData
     * @param  array<string, string>  $signed
     */
    private function succeeded(Order $order, Payment $locked, array $unverified, array $gatewayData, array $signed, ConfirmOrderPayment $confirm, FlagPaymentForReview $flag, RecordOrderAudit $audit): void
    {
        $reportedReference = $unverified['payment_id'] ?? null;

        if (! $locked->isSettled()) {
            $confirm->handle(
                $locked,
                attributes: [
                    'transaction_reference' => $locked->transaction_reference ?? $reportedReference,
                    'metadata' => $gatewayData,
                ],
                actorType: RecordOrderAudit::ACTOR_SYSTEM,
            );

            return;
        }

        if ($reportedReference !== null && $reportedReference === $locked->transaction_reference) {
            $audit->handle($locked, 'payment.notification_repeated', null, ['status_code' => $signed['status_code']], RecordOrderAudit::ACTOR_SYSTEM);

            return;
        }

        $flag->handle(
            $order,
            $locked,
            PaymentReview::TYPE_DUPLICATE_NOTIFICATION,
            "Another signed success notification arrived for already-paid attempt {$locked->idempotency_key} with a different or missing PayHere payment ID. This may be a second charge or a replayed notification (the payment ID is not signed) — check the PayHere merchant portal.",
            $signed + ['recorded_reference' => $locked->transaction_reference],
            ['success_after_settled'],
            $reportedReference,
        );
    }

    /**
     * Pending (0) or any other non-final status: noted on an unsettled attempt
     * only, never on a final one.
     *
     * @param  array<string, mixed>  $gatewayData
     */
    private function recordStatus(Payment $locked, array $gatewayData, RecordOrderAudit $audit): void
    {
        if (! in_array($locked->status, Payment::FINAL_STATUSES, true)) {
            $locked->forceFill(['metadata' => [...($locked->metadata ?? []), ...$gatewayData]])->save();
        }

        $audit->handle($locked, 'payment.gateway_status', null, ['status_code' => $gatewayData['payhere']['status_code']], RecordOrderAudit::ACTOR_SYSTEM);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function unsigned(array $params, string $key, int $max): ?string
    {
        $value = $params[$key] ?? null;

        return is_string($value) && $value !== '' ? Str::limit($value, $max, '') : null;
    }
}
