<?php

namespace App\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Support\Money;

/**
 * PayHere hosted Checkout API (sandbox). Builds the checkout form fields with
 * the server-side hash, and verifies notify callbacks with PayHere's md5sig:
 *
 *   hash   = UPPER(MD5(merchant_id . order_id . amount . currency . UPPER(MD5(secret))))
 *            where amount is exactly the two-decimal string sent in the form
 *   md5sig = UPPER(MD5(merchant_id . order_id . payhere_amount . payhere_currency . status_code . UPPER(MD5(secret))))
 *            using the payhere_amount string exactly as received (not normalised)
 *
 * PayHere does not sign payment_id, method or status_message: callers must
 * treat those as unverified. The merchant secret is only ever used inside
 * these hashes — never rendered, returned or logged.
 */
class PayHere
{
    public const STATUS_SUCCESS = '2';

    public const STATUS_PENDING = '0';

    public const STATUS_CANCELLED = '-1';

    public const STATUS_FAILED = '-2';

    public const STATUS_CHARGEDBACK = '-3';

    public function isConfigured(): bool
    {
        return config('payments.payhere.enabled') === true
            && is_string(config('payments.payhere.merchant_id')) && config('payments.payhere.merchant_id') !== ''
            && is_string(config('payments.payhere.merchant_secret')) && config('payments.payhere.merchant_secret') !== '';
    }

    public function checkoutUrl(): string
    {
        return (string) config('payments.payhere.checkout_url');
    }

    /**
     * The exact two-decimal string sent to PayHere and hashed ("1500.00").
     */
    public static function formatAmount(string $amount): string
    {
        return Money::format($amount);
    }

    /**
     * Whether a (signature-verified) payhere_amount equals the stored amount,
     * compared exactly in minor units. Anything but a plain decimal string —
     * exponents, signs, separators, whitespace — never matches.
     */
    public static function amountMatches(string $payHereAmount, string $storedAmount): bool
    {
        return Money::isValid($payHereAmount) && Money::toMinor($payHereAmount) === Money::toMinor($storedAmount);
    }

    /**
     * Splits the single stored name into PayHere's first/last name fields. A
     * one-word name is sent as both, since PayHere requires each.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 50);
        $last = mb_substr($parts[1] ?? $first, 0, 50);

        return [$first, $last];
    }

    /**
     * @return array<string, string>
     */
    public function checkoutFields(Order $order, Payment $payment, string $returnUrl, string $cancelUrl, string $notifyUrl): array
    {
        [$firstName, $lastName] = self::splitName($order->customer_name);
        $amount = self::formatAmount((string) $payment->amount);
        $items = $order->items->map(fn ($item): string => $item->product_name.' x'.$item->quantity)->implode(', ');
        $country = (string) config('payments.delivery_countries.'.$order->delivery_country, (string) $order->delivery_country);

        return [
            'merchant_id' => (string) config('payments.payhere.merchant_id'),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notify_url' => $notifyUrl,
            'order_id' => $payment->idempotency_key,
            'items' => mb_substr($items !== '' ? $items : 'Order '.$order->order_number, 0, 250),
            'currency' => $payment->currency,
            'amount' => $amount,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $order->customer_email,
            'phone' => (string) $order->customer_phone,
            'address' => trim($order->delivery_address_line1.' '.$order->delivery_address_line2),
            'city' => (string) $order->delivery_city,
            'country' => $country,
            'delivery_address' => trim($order->delivery_address_line1.' '.$order->delivery_address_line2),
            'delivery_city' => (string) $order->delivery_city,
            'delivery_country' => $country,
            'hash' => $this->checkoutHash($payment->idempotency_key, $amount, $payment->currency),
        ];
    }

    public function checkoutHash(string $orderId, string $amount, string $currency): string
    {
        return strtoupper(md5(config('payments.payhere.merchant_id').$orderId.$amount.$currency.$this->hashedSecret()));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function hasValidSignature(array $params): bool
    {
        foreach (['merchant_id', 'order_id', 'payhere_amount', 'payhere_currency', 'status_code', 'md5sig'] as $key) {
            if (! isset($params[$key]) || ! is_string($params[$key])) {
                return false;
            }
        }

        if (! hash_equals((string) config('payments.payhere.merchant_id'), $params['merchant_id'])) {
            return false;
        }

        $expected = strtoupper(md5(
            $params['merchant_id'].$params['order_id'].$params['payhere_amount'].$params['payhere_currency'].$params['status_code'].$this->hashedSecret()
        ));

        return hash_equals($expected, strtoupper($params['md5sig']));
    }

    private function hashedSecret(): string
    {
        return strtoupper(md5((string) config('payments.payhere.merchant_secret')));
    }
}
