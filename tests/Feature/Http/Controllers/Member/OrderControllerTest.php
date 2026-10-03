<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class OrderControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private function withStock(Product $product, int $quantity = 10): Product
    {
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
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

    private function placeOrder(Product $product, ?User $user, int $quantity = 1): Order
    {
        $cart = $user !== null
            ? Cart::query()->create(['user_id' => $user->id])
            : Cart::factory()->guest()->create();

        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return app(PlaceOrder::class)->handle($cart, $user, $this->customer());
    }

    public function test_a_guest_cannot_view_the_order_history_page(): void
    {
        $this->get(route('member.orders.index'))->assertRedirect(route('login'));
    }

    public function test_a_member_can_view_their_own_order_history(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $response = $this->actingAs($member)->get(route('member.orders.index'))->assertOk();

        $response->assertSee($order->order_number);
    }

    public function test_a_member_sees_only_their_own_orders(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $otherMember = $this->member();
        $myOrder = $this->placeOrder($product, $member);
        $otherOrder = $this->placeOrder($product, $otherMember);

        $response = $this->actingAs($member)->get(route('member.orders.index'))->assertOk();

        $response->assertSee($myOrder->order_number);
        $response->assertDontSee($otherOrder->order_number);
    }

    public function test_the_order_history_shows_order_number_date_total_and_statuses(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 40]));
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $response = $this->actingAs($member)->get(route('member.orders.index'))->assertOk();

        $response->assertSee($order->order_number);
        $response->assertSee($order->created_at->format('j M Y'));
        $response->assertSee('40.00', false);
        $response->assertSee('pending payment');
        $response->assertSee('pending');
    }

    /**
     * The listed total/status must come from the order's own stored
     * columns, not a live recalculation from the current product — proven
     * by changing the product's price after the order was placed and
     * confirming the history page still shows the original total.
     */
    public function test_the_order_history_uses_historical_order_data_not_live_product_data(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 40]));
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $product->update(['price' => 999]);

        $response = $this->actingAs($member)->get(route('member.orders.index'))->assertOk();

        $response->assertSee('40.00', false);
        $response->assertDontSee('999.00', false);
        $this->assertSame('40.00', $order->fresh()->total_amount);
    }

    public function test_the_order_history_shows_a_link_to_the_receipt_only_once_paid(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $pendingResponse = $this->actingAs($member)->get(route('member.orders.index'))->assertOk();
        $pendingResponse->assertDontSee(route('orders.receipt', $order), false);

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);

        $paidResponse = $this->actingAs($member)->get(route('member.orders.index'))->assertOk();
        $paidResponse->assertSee(route('orders.receipt', $order), false);
    }

    // --- individual order details -------------------------------------------

    public function test_a_member_can_view_their_own_order_details_from_the_history_page(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)->get(route('orders.show', $order))->assertOk()->assertSee($order->order_number);
    }

    /**
     * Changing the id in the URL (or guessing another order's public_id)
     * must never reach another member's order — preserves the existing
     * `AuthorizesOrderAccess` rule, now exercised from the order-history
     * context this step adds.
     */
    public function test_a_member_cannot_view_another_members_order_by_changing_the_id(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $intruder = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($intruder)->get(route('orders.show', $order))->assertForbidden();
    }
}
