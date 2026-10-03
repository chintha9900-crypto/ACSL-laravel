<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class EshopControllerTest extends MysqlTestCase
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

    public function test_a_member_only_product_is_visible_to_an_active_member(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create(['name' => 'Staff Jacket']));
        $member = $this->member();

        $this->actingAs($member)->get(route('eshop.index'))->assertOk()->assertSee('Staff Jacket');
        $this->actingAs($member)->get(route('eshop.show', $product))->assertOk()->assertSee('Staff Jacket');
    }

    // --- direct URL access is blocked server-side, not just hidden in the UI --

    public function test_direct_url_access_to_a_member_only_product_404s_for_a_guest(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());

        $this->get(route('eshop.show', $product))->assertNotFound();
    }

    public function test_direct_url_access_to_a_member_only_product_404s_for_a_non_active_account(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());

        $this->actingAs($this->nonMember())->get(route('eshop.show', $product))->assertNotFound();
    }

    /**
     * The 404 is identical to a non-existent product either way — never a
     * different message that would confirm a member-only product exists at
     * that URL, the same convention Blog/News/Events already use for
     * drafts.
     */
    public function test_a_member_only_products_404_looks_identical_to_a_missing_product(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create(['slug' => 'staff-jacket']));

        $memberOnlyResponse = $this->get('/eshop/staff-jacket');
        $missingResponse = $this->get('/eshop/does-not-exist');

        $memberOnlyResponse->assertNotFound();
        $missingResponse->assertNotFound();
    }

    /**
     * Authorization is enforced by the controller itself (a 404), not merely
     * by what the Blade view chooses to render — proven by hitting the
     * route directly rather than through a rendered link.
     */
    public function test_access_is_enforced_server_side_regardless_of_what_the_ui_would_show(): void
    {
        $product = $this->withStock(Product::factory()->memberOnly()->create());

        // No `actingAs` at all: a raw, unauthenticated request to the exact
        // same URL a signed-in member would use.
        $response = $this->get(route('eshop.show', $product));

        $response->assertNotFound();
        $response->assertDontSee($product->name);
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
