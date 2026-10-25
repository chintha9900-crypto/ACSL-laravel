<?php

namespace App\Actions\Checkout;

use App\Exceptions\CheckoutFailedException;
use App\Models\Cart;
use App\Models\EshopSetting;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentBankAccount;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every cart item is revalidated here, immediately before the order is
 * created, inside one transaction with each product and inventory row
 * locked (`lockForUpdate()`, in product-id order) — never trusting whatever
 * the cart page last rendered. Nothing here reads a price, name or SKU from
 * the request; it is always read fresh from `Product`.
 *
 * Amounts are exact integer minor units: total = item subtotal + the
 * administrator-configured Sri Lanka delivery charge, both snapshotted on
 * the order. Checkout is refused while no delivery charge is configured, so
 * an unset charge can never mean free delivery.
 *
 * Stock is reduced at placement and stays reserved while the order awaits
 * payment (including after a failed or cancelled attempt, so the customer can
 * retry) until it is paid, or is returned exactly once when the unpaid order
 * expires (`expires_at`, fixed from creation) or is cancelled
 * (Actions\Orders\ReleaseOrderStock).
 *
 * The first payment attempt is created for the chosen method; a dummy-method
 * order is marked `is_test` so a simulated order can never pass as real.
 */
class PlaceOrder
{
    /**
     * @param  array{name: string, email: string, phone?: ?string, address_line1?: ?string, address_line2?: ?string, city?: ?string, postcode?: ?string, country?: ?string}  $customer
     *
     * @throws CheckoutFailedException
     */
    public function handle(Cart $cart, ?User $user, array $customer, string $paymentMethod = Order::METHOD_BANK_TRANSFER): Order
    {
        if (! array_key_exists($paymentMethod, Order::METHOD_GATEWAYS)) {
            throw new CheckoutFailedException('Please choose a payment method.');
        }

        if (! array_key_exists((string) ($customer['country'] ?? ''), (array) config('payments.delivery_countries'))) {
            throw new CheckoutFailedException('We can only deliver within Sri Lanka.');
        }

        return DB::transaction(function () use ($cart, $user, $customer, $paymentMethod): Order {
            $deliveryCharge = EshopSetting::deliveryCharge();

            if ($deliveryCharge === null) {
                throw new CheckoutFailedException('Ordering is not available yet: the delivery charge has not been set. Please try again later.');
            }

            $bankAccountId = null;

            if ($paymentMethod === Order::METHOD_BANK_TRANSFER) {
                $bankAccountId = PaymentBankAccount::query()->activeForCurrency('LKR')->value('id');

                if ($bankAccountId === null) {
                    throw new CheckoutFailedException('Bank transfer is not available at the moment. Please choose another payment method.');
                }
            }

            // Locking the cart's own items first means a second, concurrent
            // submission of the same cart (a double-click, or a resubmitted
            // form) blocks here until the first attempt commits and clears
            // the cart — at which point it finds nothing left to order and
            // fails with the same "cart is empty" message, never a second
            // order.
            $items = $cart->items()->lockForUpdate()->orderBy('product_id')->get();

            if ($items->isEmpty()) {
                throw new CheckoutFailedException('Your cart is empty.');
            }

            $lines = [];
            $subtotalMinor = 0;

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->find($item->product_id);

                if ($product === null || ! $product->is_active) {
                    throw new CheckoutFailedException('One of the items in your cart is no longer available.');
                }

                if (! $product->isAccessibleTo($user)) {
                    throw new CheckoutFailedException("{$product->name} is only available to active members.");
                }

                $inventory = Inventory::query()->where('product_id', $product->id)->lockForUpdate()->first();
                $available = $inventory?->quantity ?? 0;

                if ($item->quantity > $available) {
                    throw new CheckoutFailedException("Only {$available} of {$product->name} left in stock.");
                }

                $unitMinor = Money::toMinor((string) $product->price);
                $lineMinor = $unitMinor * $item->quantity;
                $subtotalMinor += $lineMinor;

                $lines[] = [
                    'product' => $product,
                    'inventory' => $inventory,
                    'quantity' => $item->quantity,
                    'unit_price' => Money::fromMinor($unitMinor),
                    'line_total' => Money::fromMinor($lineMinor),
                ];
            }

            $order = $this->createOrder($user, $customer, $paymentMethod, [
                'subtotal_amount' => Money::fromMinor($subtotalMinor),
                'delivery_charge_amount' => Money::format($deliveryCharge),
                'total_amount' => Money::fromMinor($subtotalMinor + Money::toMinor($deliveryCharge)),
            ]);

            foreach ($lines as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line['product']->id,
                    'product_name' => $line['product']->name,
                    'sku' => $line['product']->sku,
                    'unit_price' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'line_total' => $line['line_total'],
                ]);

                $line['inventory']?->decrement('quantity', $line['quantity']);
            }

            Payment::forceCreate([
                'user_id' => $user?->id,
                'order_id' => $order->id,
                'gateway' => Order::METHOD_GATEWAYS[$paymentMethod],
                'bank_account_id' => $bankAccountId,
                'idempotency_key' => $order->order_number,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => Payment::STATUS_PENDING,
            ]);

            $cart->items()->delete();

            return $order;
        });
    }

    /**
     * @param  array<string, ?string>  $customer
     * @param  array{subtotal_amount: string, delivery_charge_amount: string, total_amount: string}  $amounts
     */
    private function createOrder(?User $user, array $customer, string $paymentMethod, array $amounts): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $order = new Order([
                    'user_id' => $user?->id,
                    'order_number' => $this->generateOrderNumber(),
                    'customer_name' => $customer['name'],
                    'customer_email' => $customer['email'],
                    'customer_phone' => $customer['phone'] ?? null,
                    'status' => Order::STATUS_PENDING_PAYMENT,
                    'currency' => 'LKR',
                    ...$amounts,
                    'payment_method' => $paymentMethod,
                    'delivery_address_line1' => $customer['address_line1'] ?? null,
                    'delivery_address_line2' => $customer['address_line2'] ?? null,
                    'delivery_city' => $customer['city'] ?? null,
                    'delivery_postcode' => $customer['postcode'] ?? null,
                    'delivery_country' => $customer['country'] ?? null,
                ]);
                $order->is_test = $paymentMethod === Order::METHOD_DUMMY;
                $order->save();

                $order->forceFill(['expires_at' => $order->paymentDeadlineFor($paymentMethod)])->save();

                return $order;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }

        throw new CheckoutFailedException('Could not place the order. Please try again.');
    }

    private function generateOrderNumber(): string
    {
        return 'ORD-'.Str::upper(Str::random(12));
    }
}
