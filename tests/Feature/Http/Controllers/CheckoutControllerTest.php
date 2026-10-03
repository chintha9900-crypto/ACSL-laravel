<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\Cart\ResolveCart;
use App\Actions\Checkout\PlaceOrder;
use App\Exceptions\CheckoutFailedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class CheckoutControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        // The same pattern SecurityControllerTest already uses for the
        // session cookie: exclude the guest-token cookie from encryption so
        // a plain value can be round-tripped through withCookie() across
        // several requests within one test.
        EncryptCookies::except(ResolveCart::COOKIE_NAME);
    }

    protected function tearDown(): void
    {
        EncryptCookies::flushState();

        parent::tearDown();
    }

    private function withStock(Product $product, int $quantity = 10): Product
    {
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
    }

    private function nonMember(): User
    {
        return User::factory()->create(['status' => 'pending_setup']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customer(array $overrides = []): array
    {
        return [
            'name' => 'Nimal Perera',
            'email' => 'nimal@example.test',
            'phone' => '+94 77 123 4567',
            ...$overrides,
        ];
    }

    private function guestCart(): Cart
    {
        return Cart::factory()->guest()->create();
    }

    private function addItem(Cart $cart, Product $product, int $quantity = 1): void
    {
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);
    }

    /**
     * `withUnencryptedCookie()` persists for every subsequent call in the
     * same test, so calling this once lets several sequential requests act
     * as the same guest. The default `withCookie()` has its own
     * pre-encryption step in the test client itself (separate from the
     * server-side `EncryptCookies` middleware excluded in `setUp()`), which
     * would otherwise double-encrypt the value.
     */
    private function asGuest(Cart $cart): static
    {
        return $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token);
    }

    private function addToCart(User $member, Product $product, int $quantity = 1): void
    {
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => $quantity]);
    }

    // --- successful checkout --------------------------------------------------

    public function test_a_guest_can_check_out_a_public_product(): void
    {
        $product = $this->withStock(Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC, 'price' => 20]));
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 2);

        $response = $this->asGuest($cart)->post(route('checkout.store'), $this->customer());

        $order = Order::query()->firstOrFail();
        $response->assertRedirect();
        $this->assertStringContainsString((string) $order->public_id, $response->headers->get('Location'));
        $this->assertNull($order->user_id);
        $this->assertSame('40.00', $order->total_amount);
        $this->assertSame(0, CartItem::query()->count(), 'The cart must be cleared after a successful order.');
    }

    public function test_an_active_member_can_check_out_a_public_product(): void
    {
        $product = $this->withStock(Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]));
        $member = $this->member();
        $this->addToCart($member, $product, 1);

        $this->actingAs($member)->post(route('checkout.store'), $this->customer())->assertRedirect();

        $order = Order::query()->firstOrFail();
        $this->assertSame($member->id, $order->user_id);
    }

    public function test_an_active_member_can_check_out_a_member_only_product(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());
        $member = $this->member();
        $this->addToCart($member, $product, 1);

        $this->actingAs($member)->post(route('checkout.store'), $this->customer())->assertRedirect();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame($product->id, OrderItem::query()->firstOrFail()->product_id);
    }

    // --- member-only is blocked for guests / non-active accounts ------------

    /**
     * Added while PUBLIC (so it's actually in the cart at all — `AddCartItem`
     * already refuses a guest adding a genuinely MEMBER_ONLY product), then
     * the product is switched to MEMBER_ONLY before checkout runs — proving
     * checkout re-checks access itself rather than trusting the cart's
     * earlier, now-stale admission.
     */
    public function test_a_guest_is_blocked_from_checking_out_a_product_that_became_member_only(): void
    {
        $product = $this->withStock(Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]));
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);
        $product->update(['access_type' => Product::ACCESS_MEMBER_ONLY]);

        $this->asGuest($cart)->post(route('checkout.store'), $this->customer())->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_non_active_account_is_blocked_from_checking_out_a_product_that_became_member_only(): void
    {
        $product = $this->withStock(Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]));
        $user = $this->nonMember();
        $this->addToCart($user, $product, 1);
        $product->update(['access_type' => Product::ACCESS_MEMBER_ONLY]);

        $this->actingAs($user)->post(route('checkout.store'), $this->customer())->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::query()->count());
    }

    // --- inactive product -----------------------------------------------------

    public function test_an_inactive_product_is_rejected_during_checkout(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);
        $product->update(['is_active' => false]);

        $this->asGuest($cart)->post(route('checkout.store'), $this->customer())->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::query()->count());
    }

    // --- price/name/SKU integrity ----------------------------------------------

    public function test_a_client_supplied_price_and_total_are_ignored(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 100]));
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);

        $this->asGuest($cart)->post(route('checkout.store'), [
            ...$this->customer(),
            'price' => 1,
            'unit_price' => 1,
            'total_amount' => 1,
            'total' => 1,
        ]);

        $order = Order::query()->firstOrFail();
        $this->assertSame('100.00', $order->total_amount);
        $this->assertSame('100.00', OrderItem::query()->firstOrFail()->unit_price);
    }

    public function test_order_items_snapshot_the_products_name_sku_and_price_at_the_moment_of_order(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]));
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 2);

        $this->asGuest($cart)->post(route('checkout.store'), $this->customer());

        $item = OrderItem::query()->firstOrFail();
        $this->assertSame('ACI Polo Shirt', $item->product_name);
        $this->assertSame('POLO-001', $item->sku);
        $this->assertSame('25.00', $item->unit_price);
        $this->assertSame('50.00', $item->line_total);

        $product->update(['name' => 'Renamed', 'sku' => 'POLO-999', 'price' => 999]);

        $this->assertSame('ACI Polo Shirt', $item->fresh()->product_name, 'Historical snapshots must not change when the catalogue does.');
        $this->assertSame('POLO-001', $item->fresh()->sku);
        $this->assertSame('25.00', $item->fresh()->unit_price);
    }

    // --- stock revalidation / insufficient stock -----------------------------

    public function test_quantity_and_stock_are_revalidated_at_checkout(): void
    {
        $product = $this->withStock(Product::factory()->create(), 5);
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 3);

        // Stock drops below the cart's quantity after it was added.
        Inventory::query()->where('product_id', $product->id)->update(['quantity' => 1]);

        $this->asGuest($cart)->post(route('checkout.store'), $this->customer())->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, OrderItem::query()->count());
    }

    public function test_insufficient_stock_rejects_the_whole_order(): void
    {
        $product = $this->withStock(Product::factory()->create(), 2);
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 2);

        // A quantity that was valid when added, no longer valid by the time
        // checkout runs (the cart itself already caps additions at
        // available stock, so this simulates the gap directly).
        CartItem::query()->where('product_id', $product->id)->update(['quantity' => 5]);

        $response = $this->asGuest($cart)->post(route('checkout.store'), $this->customer());

        $response->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(2, Inventory::query()->where('product_id', $product->id)->value('quantity'), 'Stock must be untouched after a rejected order.');
    }

    // --- concurrent-safe stock handling ---------------------------------------

    public function test_checkout_locks_the_inventory_row_while_revalidating_stock(): void
    {
        $product = $this->withStock(Product::factory()->create(), 10);
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->asGuest($cart)->post(route('checkout.store'), $this->customer());

        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'for update')));
    }

    /**
     * Not a true multi-process race (PHPUnit is single-threaded), but this
     * proves the mechanism: two sequential orders against the same limited
     * stock — the second must see the first's committed deduction and fail
     * cleanly, never oversell.
     */
    public function test_two_sequential_orders_against_limited_stock_never_oversell(): void
    {
        $product = $this->withStock(Product::factory()->create(), 3);
        $firstCart = $this->guestCart();
        $this->addItem($firstCart, $product, 3);
        $secondCart = $this->guestCart();
        $this->addItem($secondCart, $product, 3);

        app(PlaceOrder::class)->handle($firstCart, null, $this->customer());

        $this->assertThrows(
            fn () => app(PlaceOrder::class)->handle($secondCart, null, $this->customer(['email' => 'second@example.test'])),
            CheckoutFailedException::class,
        );

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, Inventory::query()->where('product_id', $product->id)->value('quantity'));
    }

    // --- order/order-item creation ---------------------------------------------

    public function test_an_order_is_created_with_its_items_transactionally(): void
    {
        $first = $this->withStock(Product::factory()->create(['price' => 10]));
        $second = $this->withStock(Product::factory()->create(['price' => 15]));
        $cart = $this->guestCart();
        $this->addItem($cart, $first, 2);
        $this->addItem($cart, $second, 1);

        $this->asGuest($cart)->post(route('checkout.store'), $this->customer());

        $order = Order::query()->firstOrFail();
        $this->assertSame(2, $order->items()->count());
        $this->assertSame('35.00', $order->total_amount);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
    }

    public function test_a_pending_payment_row_is_created_for_the_order_without_marking_it_paid(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 30]));
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);

        $this->asGuest($cart)->post(route('checkout.store'), $this->customer());

        $order = Order::query()->firstOrFail();
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('30.00', $payment->amount);
        $this->assertNull($payment->paid_at);
    }

    // --- unique order number / public id ----------------------------------------

    public function test_every_order_gets_a_unique_order_number_and_public_id(): void
    {
        $product = $this->withStock(Product::factory()->create(), 20);
        $cart = $this->guestCart();
        $this->asGuest($cart);

        $this->addItem($cart, $product, 1);
        $this->post(route('checkout.store'), $this->customer());

        $this->addItem($cart, $product, 1);
        $this->post(route('checkout.store'), $this->customer(['email' => 'second@example.test']));

        $orders = Order::query()->get();
        $this->assertSame(2, $orders->count());
        $this->assertNotSame($orders[0]->order_number, $orders[1]->order_number);
        $this->assertNotSame($orders[0]->public_id, $orders[1]->public_id);
        $this->assertMatchesRegularExpression('/^ORD-[A-Z0-9]+$/', $orders[0]->order_number);
    }

    // --- duplicate submission protection -----------------------------------------

    /**
     * The second submission finds nothing left in the (already-cleared)
     * cart and is rejected — the same mechanism a genuine double-click
     * would hit, proven here as two sequential calls to the same endpoint
     * from the same guest (`withCookie()` persists across both calls).
     */
    public function test_a_duplicate_checkout_submission_does_not_create_a_second_order(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);
        $this->asGuest($cart);

        $first = $this->post(route('checkout.store'), $this->customer());
        $first->assertRedirect();

        $second = $this->post(route('checkout.store'), $this->customer());
        $second->assertSessionHasErrors('cart');

        $this->assertSame(1, Order::query()->count());
    }

    // --- unauthorized order access -------------------------------------------------

    public function test_a_guest_cannot_view_an_order_without_a_valid_signature(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);
        $this->asGuest($cart)->post(route('checkout.store'), $this->customer());
        $order = Order::query()->firstOrFail();

        $this->get(route('orders.show', $order))->assertForbidden();
    }

    /**
     * Checkout now hands off straight into the payment page (E-Shop Step
     * 7), so the order's own confirmation page is checked directly instead
     * of via the checkout redirect.
     */
    public function test_a_guest_can_view_their_own_order_via_the_signed_confirmation_link(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $cart = $this->guestCart();
        $this->addItem($cart, $product, 1);
        $this->asGuest($cart)->post(route('checkout.store'), $this->customer());
        $order = Order::query()->firstOrFail();

        $signedUrl = URL::signedRoute('orders.show', $order);

        $this->get($signedUrl)->assertOk()->assertSee($product->name);
    }

    public function test_a_member_cannot_view_another_members_order(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $owner = $this->member();
        $intruder = $this->member();
        $this->addToCart($owner, $product, 1);
        $this->actingAs($owner)->post(route('checkout.store'), $this->customer());
        $order = Order::query()->firstOrFail();

        $this->actingAs($intruder)->get(route('orders.show', $order))->assertForbidden();
    }

    public function test_a_member_can_view_their_own_order_without_a_signature(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $this->addToCart($member, $product, 1);
        $this->actingAs($member)->post(route('checkout.store'), $this->customer());
        $order = Order::query()->firstOrFail();

        $this->actingAs($member)->get(route('orders.show', $order))->assertOk();
    }
}
