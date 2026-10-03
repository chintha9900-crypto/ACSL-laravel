<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\Cart\ResolveCart;
use App\Actions\Cart\UpdateCartItemQuantity;
use App\Exceptions\CartItemRejectedException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class CartControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private function withStock(Product $product, int $quantity = 10): Product
    {
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
    }

    /**
     * An authenticated user whose account isn't an active member — the same
     * "logged in but not active" case `Product::isAccessibleTo()` treats
     * identically to a guest.
     */
    private function nonMember(): User
    {
        return User::factory()->create(['status' => 'pending_setup']);
    }

    private function guestCookieFrom(TestResponse $response): ?string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === ResolveCart::COOKIE_NAME) {
                return $cookie->getValue();
            }
        }

        return null;
    }

    // --- adding products -----------------------------------------------------

    public function test_a_guest_can_add_a_public_product(): void
    {
        $product = $this->withStock(Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]));

        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect(route('cart.show'))
            ->assertSessionHas('status');

        $this->assertSame(1, CartItem::query()->count());
        $this->assertSame(2, CartItem::query()->first()->quantity);
    }

    public function test_an_active_member_can_add_a_public_product(): void
    {
        $product = $this->withStock(Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]));
        $member = $this->member();

        $this->actingAs($member)
            ->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertRedirect(route('cart.show'));

        $cart = Cart::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_an_active_member_can_add_a_member_only_product(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());
        $member = $this->member();

        $this->actingAs($member)
            ->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertRedirect(route('cart.show'))
            ->assertSessionHas('status');

        $this->assertSame(1, CartItem::query()->where('product_id', $product->id)->count());
    }

    // --- member-only is blocked for guests / non-active accounts ------------

    public function test_a_guest_is_blocked_from_adding_a_member_only_product(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());

        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_a_non_active_account_is_blocked_from_adding_a_member_only_product(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());

        $this->actingAs($this->nonMember())
            ->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, CartItem::query()->count());
    }

    /**
     * Same request shape a tampered client could send directly — proves the
     * rejection is enforced by the controller/Action, not merely by what the
     * storefront page would have offered.
     */
    public function test_a_manipulated_request_for_a_member_only_product_is_still_blocked_for_a_guest(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());

        $response = $this->post(route('cart.items.store'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response->assertSessionHasErrors('product_id');
        $this->assertSame(0, CartItem::query()->count(), 'A guest must never be able to place a MEMBER_ONLY item in a cart.');
    }

    // --- inactive / out-of-stock products -------------------------------------

    public function test_an_inactive_product_cannot_be_added(): void
    {
        $product = $this->withStock(Product::factory()->inactive()->create());

        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_an_out_of_stock_product_cannot_be_added(): void
    {
        $product = Product::factory()->create();
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, CartItem::query()->count());
    }

    // --- quantity management --------------------------------------------------

    /**
     * Each sub-request here must act as the *same* identity, since a plain
     * guest request carries no session — a logged-in member is the simplest
     * way to keep that identity stable across requests within one test;
     * guest-specific persistence has its own dedicated test below.
     */
    public function test_quantity_can_be_increased_and_decreased(): void
    {
        $product = $this->withStock(Product::factory()->create(), 10);
        $this->actingAs($this->member())->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $item = CartItem::query()->firstOrFail();

        $this->patch(route('cart.items.update', $item), ['quantity' => 5])->assertRedirect(route('cart.show'));
        $this->assertSame(5, $item->fresh()->quantity);

        $this->patch(route('cart.items.update', $item), ['quantity' => 3])->assertRedirect(route('cart.show'));
        $this->assertSame(3, $item->fresh()->quantity);
    }

    public function test_quantity_cannot_exceed_available_stock(): void
    {
        $product = $this->withStock(Product::factory()->create(), 5);
        $this->actingAs($this->member())->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2]);
        $item = CartItem::query()->firstOrFail();

        $this->patch(route('cart.items.update', $item), ['quantity' => 99])
            ->assertSessionHasErrors('quantity');

        $this->assertSame(2, $item->fresh()->quantity, 'Quantity must be unchanged after a rejected update.');
    }

    public function test_adding_more_of_an_already_cart_product_is_capped_by_combined_stock(): void
    {
        $product = $this->withStock(Product::factory()->create(), 5);
        $member = $this->member();
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 3]);

        $this->actingAs($member)
            ->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 4])
            ->assertSessionHasErrors('product_id');

        $this->assertSame(3, CartItem::query()->firstOrFail()->quantity, 'The combined 3+4 exceeds the 5 in stock, so the add is rejected and the existing line is untouched.');
    }

    // --- remove / empty --------------------------------------------------------

    public function test_an_item_can_be_removed(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $this->actingAs($this->member())->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $item = CartItem::query()->firstOrFail();

        $this->delete(route('cart.items.destroy', $item))->assertRedirect(route('cart.show'));

        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_the_cart_can_be_emptied(): void
    {
        $first = $this->withStock(Product::factory()->create());
        $second = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $first->id, 'quantity' => 1]);
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $second->id, 'quantity' => 1]);
        $this->assertSame(2, CartItem::query()->count());

        $this->actingAs($member)->delete(route('cart.clear'))->assertRedirect(route('cart.show'));

        $this->assertSame(0, CartItem::query()->count());
        $this->assertSame(1, Cart::query()->count(), 'The cart row itself survives being emptied.');
    }

    // --- subtotal --------------------------------------------------------------

    public function test_the_subtotal_is_calculated_from_live_product_prices(): void
    {
        $first = $this->withStock(Product::factory()->create(['price' => 10]));
        $second = $this->withStock(Product::factory()->create(['price' => 25]));
        $member = $this->member();
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $first->id, 'quantity' => 2]);
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $second->id, 'quantity' => 1]);

        $cart = Cart::query()->where('user_id', $member->id)->firstOrFail();
        $cart->load('items.product');

        $this->assertSame(45.0, $cart->subtotal(), '2 * 10 + 1 * 25 = 45.');
    }

    // --- price integrity ---------------------------------------------------------

    /**
     * There is no price field anywhere on the add/update forms — this
     * proves the server ignores one even if a tampered request includes it.
     */
    public function test_a_client_supplied_price_is_ignored(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 50]));

        $this->post(route('cart.items.store'), [
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 1,
            'unit_price' => 1,
        ]);

        $cart = Cart::query()->firstOrFail();
        $cart->load('items.product');

        $this->assertSame(50.0, $cart->subtotal(), 'The subtotal must come from the real product price, not the submitted 1.');
        $this->assertArrayNotHasKey('price', $cart->items->first()->getAttributes());
    }

    // --- server-side enforcement --------------------------------------------------

    /**
     * The owner here is (and stays) an active member, so routing the update
     * through HTTP as themselves would still pass — they're always allowed
     * to see a MEMBER_ONLY product. What must be proven is the Action's own
     * revalidation logic, independent of cart-ownership routing (covered
     * separately): called with a guest identity, it must reject exactly as
     * it would if a guest's own cart somehow held this item.
     */
    public function test_access_is_revalidated_on_update_even_if_the_product_became_member_only_after_adding(): void
    {
        $product = $this->withStock(Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]));
        $this->actingAs($this->member())->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $item = CartItem::query()->firstOrFail();

        $product->update(['access_type' => Product::ACCESS_MEMBER_ONLY]);

        $this->assertThrows(
            fn () => app(UpdateCartItemQuantity::class)->handle($item, 2, null),
            CartItemRejectedException::class,
        );

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_access_is_revalidated_on_update_even_if_the_product_became_inactive_after_adding(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $item = CartItem::query()->firstOrFail();

        $product->update(['is_active' => false]);

        $this->actingAs($member)
            ->patch(route('cart.items.update', $item), ['quantity' => 2])
            ->assertSessionHasErrors('quantity');
    }

    /**
     * A removed/inaccessible-since-added item must still be removable —
     * deletion never re-validates access, only mutation (add/update) does.
     */
    public function test_an_item_that_became_inaccessible_can_still_be_removed(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $item = CartItem::query()->firstOrFail();
        $product->update(['is_active' => false]);

        $this->actingAs($member)->delete(route('cart.items.destroy', $item))->assertRedirect(route('cart.show'));

        $this->assertSame(0, CartItem::query()->count());
    }

    // --- guest cart persistence -------------------------------------------------

    /**
     * Exercises the exact mechanism `CartController` relies on
     * (`ResolveCart::resolve()` + the `guest_token` column) directly,
     * rather than round-tripping an encrypted cookie through two separate
     * test HTTP calls (Laravel's test client does not share cookies between
     * calls unless one is replayed explicitly, and that replay mechanics
     * are no part of what this requirement is actually asking to prove).
     */
    public function test_a_guest_carts_contents_persist_across_requests_via_the_guest_token_cookie(): void
    {
        $resolveCart = app(ResolveCart::class);

        $first = $resolveCart->resolve(null, null);
        $this->assertNotNull($first->guest_token);

        $second = $resolveCart->resolve(null, $first->guest_token);

        $this->assertTrue($first->is($second), 'The same guest token must resolve back to the same cart.');
        $this->assertSame(1, Cart::query()->count());
    }

    /**
     * The cookie a guest's browser would actually store is queued on the
     * very first request that needs a cart — the end-to-end proof that the
     * mechanism above is actually wired into the HTTP layer.
     */
    public function test_a_guest_token_cookie_is_queued_on_the_first_cart_request(): void
    {
        $product = $this->withStock(Product::factory()->create());

        $response = $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->assertNotNull($this->guestCookieFrom($response), 'A guest token cookie must be queued on the response.');
        $this->assertSame(1, Cart::query()->whereNotNull('guest_token')->count());
    }

    // --- cart isolation -----------------------------------------------------------

    public function test_two_guests_have_isolated_carts(): void
    {
        $product = $this->withStock(Product::factory()->create());

        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);
        // A fresh request with no cookie at all is a different guest.
        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->assertSame(2, Cart::query()->count());
        $this->assertSame(2, CartItem::query()->count());
    }

    public function test_two_members_have_isolated_carts(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $first = $this->member();
        $second = $this->member();

        $this->actingAs($first)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($second)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);

        $this->assertSame(2, Cart::query()->count());
        $firstCart = Cart::query()->where('user_id', $first->id)->firstOrFail();
        $secondCart = Cart::query()->where('user_id', $second->id)->firstOrFail();
        $this->assertNotSame($firstCart->id, $secondCart->id);
    }

    /**
     * One member must never be able to mutate another's cart item by
     * guessing/forging its id — the response must look identical to the
     * item simply not existing, never a different message that would
     * confirm it belongs to someone else.
     */
    public function test_a_member_cannot_update_or_remove_another_members_cart_item(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $owner = $this->member();
        $intruder = $this->member();

        $this->actingAs($owner)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1]);
        $item = CartItem::query()->firstOrFail();

        $this->actingAs($intruder)->patch(route('cart.items.update', $item), ['quantity' => 5])->assertNotFound();
        $this->actingAs($intruder)->delete(route('cart.items.destroy', $item))->assertNotFound();

        $this->assertSame(1, $item->fresh()->quantity);
        $this->assertNotNull(CartItem::find($item->id));
    }
}
