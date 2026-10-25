<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Tests\Concerns\CreatesEligibleMembers;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class EshopControllerTest extends MysqlTestCase
{
    use CreatesEligibleMembers;
    use CreatesRenewals;
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

    // --- public products ---------------------------------------------------

    public function test_a_public_product_is_visible_to_a_guest(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Keyring', 'access_type' => Product::ACCESS_PUBLIC]));

        $this->get(route('eshop.index'))->assertOk()->assertSee('ACI Keyring');
        $this->get(route('eshop.show', $product))->assertOk()->assertSee('ACI Keyring');
    }

    public function test_a_public_product_is_visible_to_an_active_member(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Keyring', 'access_type' => Product::ACCESS_PUBLIC]));
        $member = $this->member();

        $this->actingAs($member)->get(route('eshop.index'))->assertOk()->assertSee('ACI Keyring');
        $this->actingAs($member)->get(route('eshop.show', $product))->assertOk()->assertSee('ACI Keyring');
    }

    // --- member-only products -------------------------------------------------

    public function test_a_member_only_product_is_hidden_from_the_listing_for_a_guest(): void
    {
        $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Staff Jacket']));

        $this->get(route('eshop.index'))->assertOk()->assertDontSee('Staff Jacket');
    }

    public function test_a_member_only_product_is_hidden_from_the_listing_for_a_non_active_account(): void
    {
        $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Staff Jacket']));

        $this->actingAs($this->nonMember())->get(route('eshop.index'))->assertOk()->assertDontSee('Staff Jacket');
    }

    public function test_a_member_only_product_is_visible_to_an_eligible_member(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Staff Jacket']));
        $member = $this->eligibleMember();

        $this->actingAs($member)->get(route('eshop.index'))->assertOk()->assertSee('Staff Jacket');
        $this->actingAs($member)->get(route('eshop.show', $product))->assertOk()->assertSee('Staff Jacket');
    }

    // --- direct URL access is blocked server-side, not just hidden in the UI --

    /**
     * A guest gets the members-only sign-in prompt (403) with none of the
     * product's data — not its name, price, description, stock or image.
     */
    public function test_direct_url_access_to_a_member_only_product_shows_a_sign_in_prompt_to_a_guest(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create([
            'name' => 'Secret Staff Jacket',
            'description' => 'Hidden description text',
            'price' => 98765,
        ]));

        $response = $this->get(route('eshop.show', $product));

        $response->assertForbidden()
            ->assertSee('data-members-only-dialog', false)
            ->assertSee('Please log in to your account')
            ->assertSee('action="'.route('login').'"', false)
            ->assertDontSee('Secret Staff Jacket')
            ->assertDontSee('Hidden description text')
            ->assertDontSee('98,765')
            ->assertDontSee('In stock')
            ->assertDontSee('name="product_id"', false);
        $this->assertSame(route('eshop.show', $product), session('url.intended'), 'The guest returns to this product after signing in.');
    }

    public function test_a_non_active_account_gets_the_prompt_without_a_sign_in_button_and_no_data(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Secret Staff Jacket']));

        $this->actingAs($this->nonMember())->get(route('eshop.show', $product))
            ->assertForbidden()
            ->assertSee('only with an eligible Aviation Club membership')
            ->assertDontSee('data-members-sign-in', false)
            ->assertDontSee('Secret Staff Jacket');
    }

    public function test_a_missing_or_inactive_product_still_404s(): void
    {
        $inactive = $this->withStock(Product::factory()->memberOnly()->inactive()->create(['slug' => 'old-jacket']));

        $this->get('/eshop/does-not-exist')->assertNotFound();
        $this->get(route('eshop.show', $inactive))->assertNotFound();
        $this->actingAs($this->member())->get(route('eshop.show', $inactive))->assertNotFound();
    }

    /**
     * Authorization is enforced by the controller itself, not merely by what
     * the Blade view chooses to render — proven by hitting the route
     * directly rather than through a rendered link.
     */
    public function test_access_is_enforced_server_side_regardless_of_what_the_ui_would_show(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());

        // No `actingAs` at all: a raw, unauthenticated request to the exact
        // same URL a signed-in member would use.
        $response = $this->get(route('eshop.show', $product));

        $response->assertForbidden();
        $response->assertDontSee($product->name);
    }

    // --- members-only entry point ---------------------------------------------

    public function test_guests_see_a_members_only_option_and_choosing_it_prompts_sign_in(): void
    {
        $this->withStock(Product::factory()->create(['name' => 'Public Cap']));
        $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Secret Pin', 'description' => 'Hidden pin text', 'price' => 54321]));

        $this->get(route('eshop.index'))
            ->assertOk()
            ->assertSee('data-members-filter', false)
            ->assertSee('Members only')
            ->assertDontSee('data-members-only-dialog', false);

        $response = $this->get(route('eshop.index', ['access' => 'members']))->assertOk();

        $response->assertSee('data-members-only-dialog', false)
            ->assertSee('Public Cap')
            ->assertSee('1 product')
            ->assertDontSee('Secret Pin')
            ->assertDontSee('Hidden pin text')
            ->assertDontSee('54,321');
        $this->assertSame(route('eshop.index', ['access' => 'members']), session('url.intended'));
    }

    public function test_after_signing_in_an_eligible_member_returns_to_the_members_only_catalogue(): void
    {
        $this->withStock(Product::factory()->create(['name' => 'Public Cap']));
        $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Secret Pin']));
        $member = $this->eligibleMember();
        $member->forceFill(['password' => 'correct-horse-battery'])->save();

        $this->get(route('eshop.index', ['access' => 'members']));
        $this->post(route('login'), ['email' => $member->email, 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('eshop.index', ['access' => 'members']));

        $this->get(route('eshop.index', ['access' => 'members']))
            ->assertOk()
            ->assertSee('Members-only products')
            ->assertSee('Secret Pin')
            ->assertDontSee('Public Cap')
            ->assertDontSee('data-members-only-dialog', false);
        $this->get(route('eshop.index'))->assertSee('Secret Pin')->assertSee('Public Cap');
    }

    public function test_signing_in_alone_does_not_grant_members_only_access(): void
    {
        $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Secret Pin']));

        $this->actingAs($this->nonMember())->get(route('eshop.index', ['access' => 'members']))
            ->assertOk()
            ->assertSee('data-members-only-dialog', false)
            ->assertDontSee('Secret Pin');
        $this->assertNull(session('url.intended'));
    }

    public function test_category_filter_and_pagination_keep_the_same_restrictions(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Wearables', 'slug' => 'wearables', 'is_active' => true]);
        foreach (range(1, 13) as $i) {
            $this->withStock(Product::factory()->create(['name' => sprintf('Public Item %02d', $i), 'product_category_id' => $category->id]));
        }
        $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Secret Wearable', 'product_category_id' => $category->id]));

        $this->get(route('eshop.index', ['category' => 'wearables']))->assertSee('13 products')->assertDontSee('Secret Wearable');
        $this->get(route('eshop.index', ['category' => 'wearables', 'page' => 2]))->assertSee('Public Item 13')->assertDontSee('Secret Wearable');
        $this->get(route('eshop.index', ['category' => 'wearables', 'access' => 'members', 'page' => 2]))->assertDontSee('Secret Wearable');

        $member = $this->eligibleMember();
        $this->actingAs($member)->get(route('eshop.index', ['category' => 'wearables']))->assertSee('14 products');
        $this->actingAs($member)->get(route('eshop.index', ['category' => 'wearables', 'access' => 'members']))
            ->assertSee('1 product')
            ->assertSee('Secret Wearable')
            ->assertSee('category=wearables', false);
    }

    // --- inactive products ---------------------------------------------------

    public function test_an_inactive_product_is_hidden_from_the_listing(): void
    {
        $this->withStock(Product::factory()->inactive()->create(['name' => 'Discontinued Mug']));

        $this->get(route('eshop.index'))->assertOk()->assertDontSee('Discontinued Mug');

        $this->actingAs($this->member())
            ->get(route('eshop.index'))
            ->assertOk()
            ->assertDontSee('Discontinued Mug');
    }

    public function test_an_inactive_products_direct_url_404s_even_for_an_admin(): void
    {
        $product = $this->withStock(Product::factory()->inactive()->create());

        $this->actingAs($this->admin())->get(route('eshop.show', $product))->assertNotFound();
    }

    // --- stock availability ---------------------------------------------------

    public function test_a_zero_stock_product_shows_out_of_stock_and_no_add_to_cart_control(): void
    {
        $product = Product::factory()->create(['name' => 'Sold Out Cap']);
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => 0]);

        $response = $this->get(route('eshop.show', $product))->assertOk();

        $response->assertSee('Out of stock');
        $response->assertDontSee('Add to Cart');
    }

    /**
     * The entry point itself is covered here; the actual add-to-cart
     * behaviour (access/stock revalidation, quantity handling) is covered
     * by CartControllerTest — this only proves the product page offers a
     * real, working form rather than Step 4's disabled placeholder.
     */
    public function test_an_in_stock_product_shows_a_working_add_to_cart_form(): void
    {
        $product = $this->withStock(Product::factory()->create(), 3);

        $html = $this->get(route('eshop.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Add to Cart', $html);
        $this->assertStringContainsString('action="'.route('cart.items.store').'"', $html);
        $this->assertStringContainsString('value="'.$product->id.'"', $html);
    }

    // --- category browsing ---------------------------------------------------

    public function test_the_listing_can_be_filtered_by_category(): void
    {
        $merch = ProductCategory::factory()->create(['name' => 'Merchandise', 'slug' => 'merchandise']);
        $books = ProductCategory::factory()->create(['name' => 'Books', 'slug' => 'books']);
        $this->withStock(Product::factory()->create(['name' => 'ACI Mug', 'product_category_id' => $merch->id]));
        $this->withStock(Product::factory()->create(['name' => 'Aviation Handbook', 'product_category_id' => $books->id]));

        $response = $this->get(route('eshop.index', ['category' => 'merchandise']))->assertOk();

        $response->assertSee('ACI Mug');
        $response->assertDontSee('Aviation Handbook');
    }

    // --- navigation ----------------------------------------------------------

    public function test_the_main_navigation_links_to_the_shop(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('Shop');
        $response->assertSee('href="'.route('eshop.index').'"', false);
    }
}
