<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class ProductControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $category = ProductCategory::factory()->create();

        return [
            'product_category_id' => $category->id,
            'name' => 'ACI Polo Shirt',
            'slug' => 'aci-polo-shirt',
            'sku' => 'POLO-001',
            'description' => 'Official club polo shirt.',
            'price' => 25.50,
            'access_type' => Product::ACCESS_PUBLIC,
            'quantity' => 10,
            ...$overrides,
        ];
    }

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_product_route(): void
    {
        $product = Product::factory()->create();

        $this->get(route('admin.products.index'))->assertRedirect(route('login'));
        $this->get(route('admin.products.create'))->assertRedirect(route('login'));
        $this->post(route('admin.products.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.products.show', $product))->assertRedirect(route('login'));
        $this->get(route('admin.products.edit', $product))->assertRedirect(route('login'));
        $this->patch(route('admin.products.update', $product), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('admin.products.destroy', $product))->assertRedirect(route('login'));
        $this->post(route('admin.products.activate', $product))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_product_route(): void
    {
        $product = Product::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.products.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.products.create'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.products.store'), $this->payload())->assertForbidden();
        $this->actingAs($member)->get(route('admin.products.show', $product))->assertForbidden();
        $this->actingAs($member)->get(route('admin.products.edit', $product))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.products.update', $product), $this->payload())->assertForbidden();
        $this->actingAs($member)->delete(route('admin.products.destroy', $product))->assertForbidden();
        $this->actingAs($member)->post(route('admin.products.activate', $product))->assertForbidden();
    }

    // --- CRUD ------------------------------------------------------------------

    public function test_an_admin_can_create_a_product_with_its_category_and_inventory(): void
    {
        $category = ProductCategory::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), $this->payload(['product_category_id' => $category->id]))
            ->assertRedirect(route('admin.products.index'));

        $product = Product::query()->firstOrFail();
        $this->assertSame('ACI Polo Shirt', $product->name);
        $this->assertSame('POLO-001', $product->sku);
        $this->assertSame($category->id, $product->product_category_id);
        $this->assertSame('25.50', $product->price);
        $this->assertFalse($product->is_active, 'A new product is created inactive, the same workflow as a new draft blog post.');
        $this->assertSame(10, $product->inventory->quantity);
    }

    public function test_an_admin_can_view_a_single_product(): void
    {
        $product = Product::factory()->create(['name' => 'ACI Polo Shirt']);

        $this->actingAs($this->admin())
            ->get(route('admin.products.show', $product))
            ->assertOk()
            ->assertSee('ACI Polo Shirt')
            ->assertSee($product->sku);
    }

    public function test_an_admin_can_update_a_product_and_reassign_its_category(): void
    {
        $product = Product::factory()->create();
        $newCategory = ProductCategory::factory()->create();

        $this->actingAs($this->admin())
            ->patch(route('admin.products.update', $product), $this->payload([
                'product_category_id' => $newCategory->id,
                'name' => 'Updated Name',
                'slug' => $product->slug,
                'sku' => $product->sku,
                'quantity' => 3,
            ]))
            ->assertRedirect(route('admin.products.index'));

        $product->refresh();
        $this->assertSame('Updated Name', $product->name);
        $this->assertSame($newCategory->id, $product->product_category_id);
        $this->assertSame(3, $product->inventory->fresh()->quantity);
    }

    public function test_an_admin_can_activate_and_deactivate_a_product(): void
    {
        $product = Product::factory()->inactive()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.products.activate', $product))->assertRedirect();
        $this->assertTrue($product->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.products.deactivate', $product))->assertRedirect();
        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_an_admin_can_delete_a_product_and_its_image(): void
    {
        $product = Product::factory()->create();
        $image = $product->images()->create(['image_path' => 'products/sample.jpg', 'display_order' => 0]);
        Storage::disk('public')->put($image->image_path, 'fake-bytes');

        $this->actingAs($this->admin())
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame(0, Product::query()->count());
        Storage::disk('public')->assertMissing($image->image_path);
    }

    /**
     * `cart_items.product_id` is RESTRICT at the database layer — this test
     * proves the admin-facing guard catches it first, so attempting to
     * delete a product that's in a cart never bubbles up as a raw
     * QueryException, and the product itself survives untouched.
     */
    public function test_a_product_not_in_any_cart_can_be_deleted(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('status');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_a_product_referenced_by_a_cart_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id]);

        $response = $this->actingAs($this->admin())
            ->delete(route('admin.products.destroy', $product));

        $response->assertRedirect();
        $response->assertSessionHas('warning');
        $this->assertSame(1, Product::query()->count(), 'The product must survive the rejected delete attempt.');
        $this->assertSame(1, CartItem::query()->count(), 'The cart item is left untouched — never auto-deleted.');
    }

    public function test_deleting_a_product_in_a_cart_never_exposes_a_raw_query_exception(): void
    {
        $product = Product::factory()->create();
        $cart = Cart::factory()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id]);

        // A 500 here would mean the database's own RESTRICT constraint fired
        // instead of the application-level guard — assertRedirect() alone
        // already proves the response isn't a server error, but assert it
        // explicitly so a regression here fails loudly and specifically.
        $response = $this->actingAs($this->admin())
            ->delete(route('admin.products.destroy', $product));

        $response->assertStatus(302);
        $this->assertNotSame(500, $response->getStatusCode());
    }

    // --- validation --------------------------------------------------------

    public function test_a_product_requires_its_core_fields(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), [])
            ->assertSessionHasErrors(['name', 'slug', 'sku', 'price', 'product_category_id', 'access_type', 'quantity']);
    }

    public function test_duplicate_skus_are_rejected_on_create(): void
    {
        Product::factory()->create(['sku' => 'POLO-001']);

        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), $this->payload(['sku' => 'POLO-001', 'slug' => 'another-polo']))
            ->assertSessionHasErrors('sku');

        $this->assertSame(1, Product::query()->count());
    }

    public function test_duplicate_slugs_are_rejected_on_create(): void
    {
        Product::factory()->create(['slug' => 'aci-polo-shirt']);

        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), $this->payload(['sku' => 'POLO-999']))
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, Product::query()->count());
    }

    public function test_a_product_keeps_its_own_sku_and_slug_on_update(): void
    {
        $product = Product::factory()->create(['sku' => 'POLO-001', 'slug' => 'aci-polo-shirt']);

        $this->actingAs($this->admin())
            ->patch(route('admin.products.update', $product), $this->payload([
                'sku' => 'POLO-001',
                'slug' => 'aci-polo-shirt',
                'product_category_id' => $product->product_category_id,
            ]))
            ->assertSessionHasNoErrors();
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), $this->payload(['price' => -5]))
            ->assertSessionHasErrors('price');
    }

    public function test_an_invalid_category_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), $this->payload(['product_category_id' => 999999]))
            ->assertSessionHasErrors('product_category_id');
    }

    public function test_an_invalid_access_type_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), $this->payload(['access_type' => 'SECRET']))
            ->assertSessionHasErrors('access_type');
    }

    public function test_a_negative_stock_quantity_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), $this->payload(['quantity' => -1]))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, Product::query()->count());
    }

    // --- public / member-only access type -----------------------------------

    public function test_a_public_product_is_accessible_to_everyone(): void
    {
        $product = Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]);

        $this->assertTrue($product->isAccessibleTo(null));
        $this->assertTrue($product->isAccessibleTo($this->member()));
    }

    public function test_a_member_only_product_is_only_accessible_to_an_authenticated_active_member(): void
    {
        $product = Product::factory()->memberOnly()->create();

        $this->assertFalse($product->isAccessibleTo(null));
        $this->assertTrue($product->isAccessibleTo($this->member()));
    }

    // --- image handling --------------------------------------------------------

    public function test_a_product_image_can_be_uploaded_on_create(): void
    {
        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);

        $product = Product::query()->firstOrFail();
        $image = $product->images->first();
        $this->assertNotNull($image);
        Storage::disk('public')->assertExists($image->image_path);
    }

    public function test_replacing_a_product_image_deletes_the_previous_file(): void
    {
        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);
        $product = Product::query()->firstOrFail();
        $firstPath = $product->images->first()->image_path;

        $this->actingAs($this->admin())->patch(route('admin.products.update', $product), [
            ...$this->payload([
                'product_category_id' => $product->product_category_id,
                'slug' => $product->slug,
                'sku' => $product->sku,
            ]),
            'image' => $this->pngUpload('second.png'),
        ]);

        $product->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        $this->assertSame(1, $product->images()->count(), 'There is still only one image slot.');
        Storage::disk('public')->assertExists($product->images->first()->image_path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), [
                ...$this->payload(),
                'image' => $this->pdfUpload(),
            ])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, Product::query()->count());
    }
}
