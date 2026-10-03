<?php

namespace Tests\Feature;

use App\Actions\Cart\MergeGuestCart;
use App\Actions\Cart\ResolveCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * E-Shop Step 6 — the guest-cart-merge-on-login rule. `MergeGuestCartOnLogin`
 * (registered on `Illuminate\Auth\Events\Login` in `AppServiceProvider`)
 * drives this in production; these tests call `MergeGuestCart` directly,
 * the same way the inventory/cart test suites already prefer exercising an
 * Action's own logic over fighting HTTP cookie encryption in a test client.
 */
class GuestCartMergeTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private function withStock(Product $product, int $quantity): Product
    {
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
    }

    public function test_a_guest_carts_items_are_merged_into_the_members_cart_on_login(): void
    {
        $product = $this->withStock(Product::factory()->create(), 10);
        $guestCart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $product->id, 'quantity' => 2]);
        $member = $this->member();

        app(MergeGuestCart::class)->handle($member, $guestCart->guest_token);

        $memberCart = Cart::query()->where('user_id', $member->id)->firstOrFail();
        $mergedItem = $memberCart->items()->firstOrFail();
        $this->assertSame(1, $memberCart->items()->count());
        $this->assertSame($product->id, $mergedItem->product_id);
        $this->assertSame(2, $mergedItem->quantity);
    }

    public function test_the_guest_cart_is_deleted_after_a_successful_merge(): void
    {
        $product = $this->withStock(Product::factory()->create(), 10);
        $guestCart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $member = $this->member();

        app(MergeGuestCart::class)->handle($member, $guestCart->guest_token);

        $this->assertNull(Cart::find($guestCart->id));
        $this->assertSame(0, CartItem::query()->where('cart_id', $guestCart->id)->count());
    }

    public function test_duplicate_products_are_merged_by_combined_quantity(): void
    {
        $product = $this->withStock(Product::factory()->create(), 10);
        $member = $this->member();
        $memberCart = Cart::query()->create(['user_id' => $member->id]);
        CartItem::factory()->create(['cart_id' => $memberCart->id, 'product_id' => $product->id, 'quantity' => 3]);

        $guestCart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $product->id, 'quantity' => 2]);

        app(MergeGuestCart::class)->handle($member, $guestCart->guest_token);

        $this->assertSame(1, $memberCart->items()->count(), 'One combined line, not two.');
        $this->assertSame(5, $memberCart->items()->firstOrFail()->quantity);
    }

    public function test_the_merged_quantity_is_capped_at_available_stock_instead_of_being_dropped(): void
    {
        $product = $this->withStock(Product::factory()->create(), 4);
        $member = $this->member();
        $memberCart = Cart::query()->create(['user_id' => $member->id]);
        CartItem::factory()->create(['cart_id' => $memberCart->id, 'product_id' => $product->id, 'quantity' => 3]);

        $guestCart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $product->id, 'quantity' => 3]);

        app(MergeGuestCart::class)->handle($member, $guestCart->guest_token);

        // Combined demand is 6, but only 4 are in stock — capped, not
        // rejected outright: the item survives the merge.
        $this->assertSame(4, $memberCart->items()->firstOrFail()->quantity);
    }

    public function test_an_item_that_became_inactive_since_being_added_is_dropped_during_merge(): void
    {
        $product = $this->withStock(Product::factory()->inactive()->create(), 10);
        $guestCart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $member = $this->member();

        app(MergeGuestCart::class)->handle($member, $guestCart->guest_token);

        $memberCart = Cart::query()->where('user_id', $member->id)->first();
        $this->assertSame(0, $memberCart?->items()->count() ?? 0);
    }

    public function test_a_valid_item_is_not_lost_when_merged_alongside_an_invalid_one(): void
    {
        $valid = $this->withStock(Product::factory()->create(), 10);
        $invalid = $this->withStock(Product::factory()->inactive()->create(), 10);
        $guestCart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $valid->id, 'quantity' => 2]);
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $invalid->id, 'quantity' => 1]);
        $member = $this->member();

        app(MergeGuestCart::class)->handle($member, $guestCart->guest_token);

        $memberCart = Cart::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame(1, $memberCart->items()->count());
        $this->assertSame($valid->id, $memberCart->items()->firstOrFail()->product_id);
    }

    public function test_merging_with_no_guest_cart_for_that_token_is_a_safe_no_op(): void
    {
        $member = $this->member();

        app(MergeGuestCart::class)->handle($member, 'this-token-does-not-exist');

        $this->assertSame(0, Cart::query()->where('user_id', $member->id)->count());
    }

    /**
     * End-to-end proof that the `Login` event is actually wired to the
     * merge (`AppServiceProvider`'s `Event::listen(Login::class, ...)`),
     * not just that the Action works in isolation. The request bound to
     * the container is given the cookie directly rather than routed
     * through a real HTTP call: the cookie-queuing mechanism itself is
     * already covered separately (`CartControllerTest`), and round-tripping
     * an encrypted cookie through two independent test-client calls here
     * would test Laravel's cookie encryption, not this listener.
     */
    public function test_logging_in_merges_the_guest_cart_identified_by_the_cookie(): void
    {
        $product = $this->withStock(Product::factory()->create(), 10);
        $guestCart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $guestCart->id, 'product_id' => $product->id, 'quantity' => 1]);
        $member = $this->member();

        $request = Request::create('/login');
        $request->cookies->set(ResolveCart::COOKIE_NAME, $guestCart->guest_token);
        app()->instance('request', $request);

        event(new Login('web', $member, false));

        $memberCart = Cart::query()->where('user_id', $member->id)->first();
        $this->assertNotNull($memberCart);
        $this->assertSame(1, $memberCart->items()->count());
        $this->assertNull(Cart::find($guestCart->id), 'The guest cart must be gone after the merge.');
    }
}
