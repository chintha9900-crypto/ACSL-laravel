<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEligibleMembers;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class ProductControllerTest extends MysqlTestCase
{
    use CreatesEligibleMembers;
    use CreatesRenewals;
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

    public function test_a_member_only_product_is_only_accessible_to_an_eligible_member(): void
    {
        $product = Product::factory()->memberOnly()->create();

        $this->assertFalse($product->isAccessibleTo(null));
        $this->assertFalse($product->isAccessibleTo($this->member()), 'An active account without a membership is not eligible.');
        $this->assertFalse($product->isAccessibleTo($this->admin()), 'An admin role is not proof of membership.');
        $this->assertTrue($product->isAccessibleTo($this->eligibleMember()));
    }

    // --- image handling (gallery) -----------------------------------------------

    /**
     * @param  array<string, mixed>  $extra
     */
    private function updateWith(Product $product, array $extra): TestResponse
    {
        return $this->actingAs($this->admin())->patch(route('admin.products.update', $product), [
            ...$this->payload([
                'product_category_id' => $product->product_category_id,
                'slug' => $product->slug,
                'sku' => $product->sku,
            ]),
            ...$extra,
        ]);
    }

    private function productWithImages(int $count): Product
    {
        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            ...$this->payload(),
            'images' => array_map(fn (int $i) => $this->pngUpload("photo-{$i}.png"), range(1, $count)),
        ])->assertSessionHasNoErrors();

        return Product::query()->firstOrFail();
    }

    public function test_three_or_more_images_can_be_uploaded_at_once_with_generated_names(): void
    {
        $product = $this->productWithImages(4);

        $images = $product->images()->get();
        $this->assertCount(4, $images);
        $this->assertSame([0, 1, 2, 3], $images->pluck('display_order')->all());
        foreach ($images as $image) {
            $this->assertMatchesRegularExpression('#^products/[0-9a-z]{26}\.png$#', $image->image_path);
            Storage::disk('public')->assertExists($image->image_path);
        }
    }

    public function test_new_uploads_are_added_to_the_existing_gallery(): void
    {
        $product = $this->productWithImages(1);
        $first = $product->images()->first();

        $this->updateWith($product, ['images' => [$this->pngUpload('second.png'), $this->pngUpload('third.png')]])->assertSessionHasNoErrors();

        $this->assertSame(3, $product->images()->count());
        $this->assertSame($first->id, $product->images()->first()->id, 'The existing main image stays first.');
        Storage::disk('public')->assertExists($first->image_path);
    }

    public function test_the_main_image_and_order_can_be_changed(): void
    {
        $product = $this->productWithImages(3);
        [$a, $b, $c] = $product->images()->get()->all();

        $this->updateWith($product, ['image_order' => [$a->id => 3, $b->id => 1, $c->id => 2]])->assertSessionHasNoErrors();
        $this->assertSame([$b->id, $c->id, $a->id], $product->images()->pluck('id')->all());

        $this->updateWith($product, ['primary_image' => $a->id])->assertSessionHasNoErrors();
        $this->assertSame([$a->id, $b->id, $c->id], $product->images()->pluck('id')->all());
        $this->assertSame([0, 1, 2], $product->images()->pluck('display_order')->all());
    }

    public function test_removing_an_image_deletes_only_that_file(): void
    {
        $product = $this->productWithImages(3);
        [$a, $b, $c] = $product->images()->get()->all();

        $this->updateWith($product, ['remove_images' => [$b->id]])->assertSessionHasNoErrors();

        $this->assertSame([$a->id, $c->id], $product->images()->pluck('id')->all());
        Storage::disk('public')->assertMissing($b->image_path);
        Storage::disk('public')->assertExists($a->image_path);
        Storage::disk('public')->assertExists($c->image_path);
    }

    public function test_a_file_still_referenced_by_another_product_is_never_deleted(): void
    {
        $product = $this->productWithImages(1);
        $shared = $product->images()->first();
        $other = Product::factory()->create();
        $other->images()->create(['image_path' => $shared->image_path, 'display_order' => 0]);

        $this->updateWith($product, ['remove_images' => [$shared->id]])->assertSessionHasNoErrors();
        Storage::disk('public')->assertExists($shared->image_path);

        $this->actingAs($this->admin())->delete(route('admin.products.destroy', $other));
        $this->assertSame(0, ProductImage::query()->count());
    }

    public function test_another_products_image_cannot_be_removed_or_made_main(): void
    {
        $product = $this->productWithImages(1);
        $other = Product::factory()->create();
        $foreign = $other->images()->create(['image_path' => 'products/foreign.png', 'display_order' => 0]);
        Storage::disk('public')->put('products/foreign.png', 'x');

        $this->updateWith($product, ['remove_images' => [$foreign->id], 'primary_image' => $foreign->id])
            ->assertSessionHasErrors(['remove_images.0', 'primary_image']);

        $this->assertTrue($foreign->fresh() !== null);
        Storage::disk('public')->assertExists('products/foreign.png');
    }

    public function test_invalid_and_oversized_images_are_rejected_and_existing_images_survive(): void
    {
        $product = $this->productWithImages(2);
        $before = $product->images()->pluck('image_path')->all();
        $maxKb = config('uploads.product_image.max_kb');

        $this->updateWith($product, ['images' => [$this->pdfUpload('fake.png')], 'remove_images' => [$product->images()->first()->id]])
            ->assertSessionHasErrors('images.0');
        $this->updateWith($product, ['images' => [UploadedFile::fake()->create('huge.png', $maxKb + 1, 'image/png')]])
            ->assertSessionHasErrors('images.0');

        $this->assertSame($before, $product->images()->pluck('image_path')->all(), 'Nothing changes on a failed update.');
        foreach ($before as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    public function test_the_gallery_size_limit_is_enforced(): void
    {
        config(['uploads.product_image.max_files' => 3]);
        $product = $this->productWithImages(2);

        $this->updateWith($product, ['images' => [$this->pngUpload('a.png'), $this->pngUpload('b.png')]])->assertSessionHasErrors('images');
        $this->assertSame(2, $product->images()->count());

        $this->updateWith($product, [
            'images' => [$this->pngUpload('a.png'), $this->pngUpload('b.png')],
            'remove_images' => [$product->images()->first()->id],
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $product->images()->count());
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), [
                ...$this->payload(),
                'images' => [$this->pdfUpload()],
            ])
            ->assertSessionHasErrors('images.0');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_edit_form_shows_existing_images_with_gallery_controls(): void
    {
        $product = $this->productWithImages(3);
        $first = $product->images()->first();

        $this->actingAs($this->admin())->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($first->image_path), false)
            ->assertSee('name="images[]" type="file" multiple', false)
            ->assertSee('name="primary_image" value="'.$first->id.'"', false)
            ->assertSee('name="remove_images[]"', false)
            ->assertSee('name="image_order['.$first->id.']"', false);
    }

    public function test_users_without_commerce_permission_cannot_change_images(): void
    {
        $product = $this->productWithImages(1);
        $image = $product->images()->first();

        foreach ([$this->member(), $this->dev()] as $user) {
            $this->actingAs($user)->patch(route('admin.products.update', $product), [
                ...$this->payload(['product_category_id' => $product->product_category_id, 'slug' => $product->slug, 'sku' => $product->sku]),
                'remove_images' => [$image->id],
                'images' => [$this->pngUpload('evil.png')],
            ])->assertForbidden();
        }
        auth()->logout();
        $this->patch(route('admin.products.update', $product), ['remove_images' => [$image->id]])->assertRedirect(route('login'));

        $this->assertSame(1, $product->images()->count());
        Storage::disk('public')->assertExists($image->image_path);
    }

    public function test_the_shop_shows_the_main_image_and_the_product_page_shows_the_gallery(): void
    {
        $product = $this->productWithImages(3);
        $product->forceFill(['is_active' => true])->save();
        Inventory::query()->updateOrCreate(['product_id' => $product->id], ['quantity' => 5]);
        [$a, $b, $c] = $product->images()->get()->all();
        $this->updateWith($product, ['primary_image' => $c->id])->assertSessionHasNoErrors();
        auth()->logout();

        $listing = $this->get(route('eshop.index'))->assertOk()->getContent();
        $this->assertStringContainsString(Storage::disk('public')->url($c->image_path), $listing);
        $this->assertStringNotContainsString(Storage::disk('public')->url($a->image_path), $listing, 'Only the main image in the listing.');

        $this->get(route('eshop.show', $product))
            ->assertOk()
            ->assertSee('data-gallery-thumbs', false)
            ->assertSeeInOrder([Storage::disk('public')->url($c->image_path), Storage::disk('public')->url($a->image_path), Storage::disk('public')->url($b->image_path)], false);
    }

    public function test_a_single_image_product_page_has_no_gallery_strip(): void
    {
        $product = $this->productWithImages(1);
        $product->forceFill(['is_active' => true])->save();
        auth()->logout();

        $this->get(route('eshop.show', $product))->assertOk()->assertDontSee('data-gallery-thumbs', false);
    }
    // --- four-image limit ----------------------------------------------------------

    public function test_the_configured_limit_is_four_images_including_the_main_image(): void
    {
        $this->assertSame(4, config('uploads.product_image.max_files'));

        $this->actingAs($this->admin())->get(route('admin.products.create'))
            ->assertOk()
            ->assertSee('Up to 4 images per product, including the main image.');
    }

    public function test_one_and_four_images_are_accepted_on_create(): void
    {
        $this->assertSame(1, $this->productWithImages(1)->images()->count());

        Product::query()->each(fn (Product $product) => $product->delete());
        $this->assertSame(4, $this->productWithImages(4)->images()->count());
    }

    public function test_five_images_are_rejected_on_create_and_nothing_is_stored(): void
    {
        $this->actingAs($this->admin())->post(route('admin.products.store'), [
            ...$this->payload(),
            'images' => array_map(fn (int $i) => $this->pngUpload("five-{$i}.png"), range(1, 5)),
        ])->assertSessionHasErrors(['images' => 'A product can have at most 4 images, including the main image.']);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles('products'));
    }

    public function test_adding_to_an_existing_gallery_counts_existing_images(): void
    {
        $product = $this->productWithImages(3);

        $this->updateWith($product, ['images' => [$this->pngUpload('a.png'), $this->pngUpload('b.png')]])
            ->assertSessionHasErrors('images');
        $this->assertSame(3, $product->images()->count());

        $this->updateWith($product, ['images' => [$this->pngUpload('fourth.png')]])->assertSessionHasNoErrors();
        $this->assertSame(4, $product->images()->count());

        $this->updateWith($product, ['images' => [$this->pngUpload('fifth.png')]])->assertSessionHasErrors('images');
        $this->assertSame(4, $product->images()->count());
    }

    public function test_a_full_gallery_image_can_be_replaced_in_one_save_keeping_order_and_main_image(): void
    {
        $product = $this->productWithImages(4);
        [$a, $b, $c, $d] = $product->images()->get()->all();

        $this->updateWith($product, [
            'remove_images' => [$c->id],
            'images' => [$this->pngUpload('replacement.png')],
            'primary_image' => $b->id,
        ])->assertSessionHasNoErrors();

        $images = $product->images()->get();
        $this->assertCount(4, $images);
        $this->assertSame($b->id, $images[0]->id, 'Chosen main image first.');
        $this->assertSame([$b->id, $a->id, $d->id], $images->take(3)->pluck('id')->all());
        $this->assertNotContains($c->id, $images->pluck('id')->all());
        Storage::disk('public')->assertMissing($c->image_path);
        Storage::disk('public')->assertExists($images[3]->image_path);
    }

    public function test_removing_one_from_another_products_list_does_not_free_a_slot(): void
    {
        $product = $this->productWithImages(4);
        $other = Product::factory()->create();
        $foreign = $other->images()->create(['image_path' => 'products/foreign.png', 'display_order' => 0]);

        $this->updateWith($product, ['remove_images' => [$foreign->id], 'images' => [$this->pngUpload('x.png')]])
            ->assertSessionHasErrors(['remove_images.0', 'images']);
        $this->assertSame(4, $product->images()->count());
    }

    public function test_a_product_above_the_new_limit_keeps_its_images_and_can_be_trimmed(): void
    {
        $product = $this->productWithImages(4);
        Storage::disk('public')->put('products/legacy-5.png', 'png');
        $legacy = $product->images()->create(['image_path' => 'products/legacy-5.png', 'display_order' => 4]);

        $this->actingAs($this->admin())->get(route('admin.products.edit', $product))
            ->assertOk()
            ->assertSee('This product has 5 images, more than the current limit of 4.', false);

        // Ordinary edits still save, and no image is deleted automatically.
        $this->updateWith($product, ['name' => 'Renamed Polo'])->assertSessionHasNoErrors();
        $this->assertSame(5, $product->images()->count());
        $this->assertSame('Renamed Polo', $product->fresh()->name);

        // Adding is refused until it is back within the limit; removal is allowed.
        $this->updateWith($product, ['images' => [$this->pngUpload('new.png')]])->assertSessionHasErrors('images');
        $this->updateWith($product, ['remove_images' => [$legacy->id]])->assertSessionHasNoErrors();
        $this->assertSame(4, $product->images()->count());
        Storage::disk('public')->assertMissing('products/legacy-5.png');
    }

    public function test_the_product_page_gallery_shows_at_most_four_images(): void
    {
        $product = $this->productWithImages(4);
        $product->images()->create(['image_path' => 'products/legacy-5.png', 'display_order' => 4]);
        $product->forceFill(['is_active' => true])->save();
        auth()->logout();

        $html = $this->get(route('eshop.show', $product))->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'data-gallery-thumb '));
        $this->assertStringNotContainsString('products/legacy-5.png', $html);
        $this->assertStringContainsString('class="mt-3 grid grid-cols-4 gap-2"', $html);
    }

    public function test_a_failed_validation_never_changes_a_full_gallery(): void
    {
        $product = $this->productWithImages(4);
        $before = $product->images()->pluck('image_path', 'id')->all();

        $this->updateWith($product, [
            'remove_images' => [array_key_first($before)],
            'images' => [$this->pdfUpload('fake.png')],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame($before, $product->images()->pluck('image_path', 'id')->all());
        foreach ($before as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }
}
