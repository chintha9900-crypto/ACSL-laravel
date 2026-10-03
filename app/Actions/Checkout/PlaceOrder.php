<?php

namespace App\Actions\Checkout;

use App\Exceptions\CheckoutFailedException;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every cart item is revalidated here, immediately before the order is
 * created, inside one transaction with each product and inventory row
 * locked (`lockForUpdate()`) — never trusting whatever the cart page last
 * rendered. Nothing here reads a price, name or SKU from the request; it is
 * always read fresh from `Product`.
 *
 * There is no stock-reservation ledger yet (deferred —
 * docs/database/12_ECOMMERCE_SCHEMA.md §7.2/7.3), so the current order model
 * reduces stock immediately at order placement rather than reserving it —
 * the simplest safe behaviour available with today's schema. A cancelled
 * order does not currently restore stock; that is a follow-up once
 * cancellation itself is built.
 */
class PlaceOrder
{
    /**
     * @param  array{name: string, email: string, phone: ?string}  $customer
     *
     * @throws CheckoutFailedException
     */
    public function handle(Cart $cart, ?User $user, array $customer): Order
    {
        return DB::transaction(function () use ($cart, $user, $customer): Order {
            // Locking the cart's own items first means a second, concurrent
            // submission of the same cart (a double-click, or a resubmitted
            // form) blocks here until the first attempt commits and clears
            // the cart — at which point it finds nothing left to order and
            // fails with the same "cart is empty" message, never a second
            // order.
            $items = $cart->items()->lockForUpdate()->get();

            if ($items->isEmpty()) {
                throw new CheckoutFailedException('Your cart is empty.');
            }

            $lines = [];
            $total = 0.0;

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

                $unitPrice = (float) $product->price;
                $lineTotal = round($unitPrice * $item->quantity, 2);
                $total += $lineTotal;

                $lines[] = [
                    'product' => $product,
                    'inventory' => $inventory,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ];
            }

            $order = $this->createOrder($user, $customer, round($total, 2));

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
                'gateway' => Payment::GATEWAY_MANUAL_BANK_TRANSFER,
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
     * @param  array{name: string, email: string, phone: ?string}  $customer
     */
    private function createOrder(?User $user, array $customer, float $total): Order
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return Order::create([
                    'user_id' => $user?->id,
                    'order_number' => $this->generateOrderNumber(),
                    'customer_name' => $customer['name'],
                    'customer_email' => $customer['email'],
                    'customer_phone' => $customer['phone'] ?? null,
                    'status' => Order::STATUS_PENDING_PAYMENT,
                    'currency' => 'LKR',
                    'total_amount' => $total,
                ]);
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
