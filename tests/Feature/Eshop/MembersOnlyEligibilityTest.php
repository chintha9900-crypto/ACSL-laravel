<?php

namespace Tests\Feature\Eshop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesEligibleMembers;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * Members-only products are for an eligible membership only (an active
 * account with a currently valid term) — not for any signed-in account, and
 * not for admin/editor roles. Checked on the listing, product page, cart and
 * checkout, including manipulated requests.
 */
class MembersOnlyEligibilityTest extends MysqlTestCase
{
    use CreatesEligibleMembers;
    use CreatesEshopOrders;
    use CreatesRenewals;
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
    }

    private function memberProduct(): Product
    {
        return $this->stockedProduct(2500, 5)->forceFill([
            'name' => 'Secret Wings Pin',
            'description' => 'Hidden members pin',
            'access_type' => Product::ACCESS_MEMBER_ONLY,
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ineligibleUsers(): array
    {
        return [
            'active account without a membership' => ['plainAccount'],
            'admin without a membership' => ['adminAccount'],
            'editor without a membership' => ['editorAccount'],
            'expired membership' => ['expiredMember'],
            'term past its dates but not yet expired' => ['lapsedButNotYetExpiredMember'],
            'suspended account with a valid membership' => ['suspendedMember'],
        ];
    }

    protected function plainAccount(): User
    {
        return User::factory()->active()->create();
    }

    protected function adminAccount(): User
    {
        return $this->admin();
    }

    protected function editorAccount(): User
    {
        return $this->editor();
    }

    protected function suspendedMember(): User
    {
        $user = $this->eligibleMember();
        $user->forceFill(['status' => 'suspended'])->save();

        return $user->fresh();
    }

    #[DataProvider('ineligibleUsers')]
    public function test_ineligible_users_cannot_see_open_or_buy_members_only_products(string $maker): void
    {
        $product = $this->memberProduct();
        $product->save();
        $public = $this->stockedProduct(1000)->forceFill(['name' => 'Public Cap']);
        $public->save();
        $user = $this->{$maker}();

        $this->assertFalse($user->hasEligibleMembership());
        $this->assertFalse($product->isAccessibleTo($user));

        if ($user->status !== 'active') {
            // Suspended accounts are stopped by the existing auth rules; the
            // product rule alone must still refuse them.
            return;
        }

        $this->actingAs($user)->get(route('eshop.index'))
            ->assertOk()
            ->assertSee('Public Cap')
            ->assertDontSee('Secret Wings Pin')
            ->assertDontSee('Hidden members pin')
            ->assertDontSee('data-membership-required', false);

        $this->actingAs($user)->get(route('eshop.index', ['access' => 'members']))
            ->assertOk()
            ->assertSee('data-members-only-dialog', false)
            ->assertSee('only with an eligible Aviation Club membership')
            ->assertDontSee('Secret Wings Pin');

        $this->actingAs($user)->get(route('eshop.show', $product))
            ->assertForbidden()
            ->assertDontSee('Secret Wings Pin')
            ->assertDontSee('2,500.00');

        $this->actingAs($user)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasErrors('product_id');
        $this->assertSame(0, CartItem::query()->where('product_id', $product->id)->count());
    }

    public function test_guests_cannot_see_open_or_buy_members_only_products(): void
    {
        $product = $this->memberProduct();
        $product->save();

        $this->get(route('eshop.index', ['access' => 'members']))
            ->assertOk()
            ->assertSee('Please log in to your account')
            ->assertSee('action="'.route('login').'"', false)
            ->assertDontSee('Secret Wings Pin');
        $this->get(route('eshop.show', $product))->assertForbidden()->assertDontSee('Secret Wings Pin');
        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasErrors('product_id');
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_an_eligible_member_can_view_add_and_check_out_a_members_only_product(): void
    {
        $product = $this->memberProduct();
        $product->save();
        $member = $this->eligibleMember();

        $this->assertTrue($member->hasEligibleMembership());
        $this->actingAs($member)->get(route('eshop.index'))->assertSee('Secret Wings Pin')->assertDontSee('data-membership-required', false);
        $this->actingAs($member)->get(route('eshop.show', $product))
            ->assertOk()
            ->assertSee('Secret Wings Pin')
            ->assertSee('Add to Cart')
            ->assertSee('then check out from the', false)
            ->assertDontSee('Checkout is coming soon');

        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->actingAs($member)->post(route('checkout.store'), $this->checkoutDetails(['email' => $member->email]))->assertSessionHasNoErrors();

        $this->assertSame($product->id, Order::query()->sole()->items()->sole()->product_id);
    }

    public function test_a_cart_item_planted_for_an_ineligible_user_is_refused_at_checkout_and_quantity_update(): void
    {
        $product = $this->memberProduct();
        $product->save();
        $user = $this->expiredMember();
        // Manipulated state: the item is in the cart although the user may not buy it.
        $cart = Cart::factory()->create(['user_id' => $user->id]);
        $item = CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($user)->patch(route('cart.items.update', $item), ['quantity' => 2])->assertSessionHasErrors('quantity');
        $this->actingAs($user)->post(route('checkout.store'), $this->checkoutDetails(['email' => $user->email]))->assertSessionHasErrors('cart');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_membership_lapsing_between_cart_and_checkout_blocks_the_order(): void
    {
        $product = $this->memberProduct();
        $product->save();
        $member = $this->eligibleMember();
        $this->actingAs($member)->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertSessionHasNoErrors();

        $member->membership->terms()->update(['starts_on' => now()->subYear()->toDateString(), 'expires_on' => now()->subDay()->toDateString()]);
        $member = $member->fresh();

        $this->actingAs($member)->post(route('checkout.store'), $this->checkoutDetails(['email' => $member->email]))->assertSessionHasErrors('cart');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_public_products_stay_available_to_everyone_with_working_purchase_controls(): void
    {
        $public = $this->stockedProduct(1000)->forceFill(['name' => 'Public Cap']);
        $public->save();

        foreach ([null, User::factory()->active()->create(), $this->eligibleMember()] as $viewer) {
            $viewer ? $this->actingAs($viewer) : auth()->logout();

            $this->get(route('eshop.show', $public))
                ->assertOk()
                ->assertSee('name="product_id" value="'.$public->id.'"', false)
                ->assertSee('Add to Cart')
                ->assertSee('href="'.route('cart.show').'"', false)
                ->assertDontSee('Checkout is coming soon');
        }
    }
}
