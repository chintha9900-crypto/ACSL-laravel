<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\ProductCategory;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class ProductCategoryControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_product_category_route(): void
    {
        $category = ProductCategory::factory()->create();

        $this->get(route('admin.product-categories.index'))->assertRedirect(route('login'));
        $this->post(route('admin.product-categories.store'), ['name' => 'X', 'slug' => 'x'])->assertRedirect(route('login'));
        $this->patch(route('admin.product-categories.update', $category), ['name' => 'X', 'slug' => 'x'])->assertRedirect(route('login'));
        $this->delete(route('admin.product-categories.destroy', $category))->assertRedirect(route('login'));
        $this->post(route('admin.product-categories.activate', $category))->assertRedirect(route('login'));
        $this->post(route('admin.product-categories.deactivate', $category))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_product_category_route(): void
    {
        $category = ProductCategory::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.product-categories.index'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.product-categories.store'), ['name' => 'X', 'slug' => 'x'])->assertForbidden();
        $this->actingAs($member)->patch(route('admin.product-categories.update', $category), ['name' => 'X', 'slug' => 'x'])->assertForbidden();
        $this->actingAs($member)->delete(route('admin.product-categories.destroy', $category))->assertForbidden();
        $this->actingAs($member)->post(route('admin.product-categories.activate', $category))->assertForbidden();
    }

    // --- CRUD ------------------------------------------------------------------

    public function test_an_admin_can_create_a_category(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.product-categories.store'), [
                'name' => 'Merchandise',
                'slug' => 'merchandise',
                'description' => 'Club branded merchandise.',
            ])
            ->assertRedirect(route('admin.product-categories.index'));

        $category = ProductCategory::query()->firstOrFail();
        $this->assertSame('Merchandise', $category->name);
        $this->assertSame('merchandise', $category->slug);
        $this->assertTrue($category->is_active);
    }

    public function test_an_admin_can_update_a_category(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Old name']);

        $this->actingAs($this->admin())
            ->patch(route('admin.product-categories.update', $category), [
                'name' => 'New name',
                'slug' => $category->slug,
            ])
            ->assertRedirect(route('admin.product-categories.index'));

        $this->assertSame('New name', $category->fresh()->name);
    }

    public function test_an_admin_can_activate_and_deactivate_a_category(): void
    {
        $category = ProductCategory::factory()->create(['is_active' => true]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.product-categories.deactivate', $category))->assertRedirect();
        $this->assertFalse($category->fresh()->is_active);

        $this->actingAs($admin)->post(route('admin.product-categories.activate', $category))->assertRedirect();
        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_an_admin_can_delete_an_empty_category(): void
    {
        $category = ProductCategory::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.product-categories.destroy', $category))
            ->assertRedirect(route('admin.product-categories.index'));

        $this->assertSame(0, ProductCategory::query()->count());
    }

    /**
     * `product_category_id` is RESTRICT, unlike blog's SET NULL — deletion
     * must be rejected while products are still assigned, not left to the
     * database to throw.
     */
    public function test_a_category_with_products_cannot_be_deleted(): void
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create(['product_category_id' => $category->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.product-categories.destroy', $category))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertSame(1, ProductCategory::query()->count());
        $this->assertNotNull($product->fresh());
    }

    // --- validation --------------------------------------------------------

    public function test_a_category_requires_a_name_and_slug(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.product-categories.store'), ['name' => '', 'slug' => ''])
            ->assertSessionHasErrors(['name', 'slug']);
    }

    public function test_duplicate_category_slugs_are_rejected_on_create(): void
    {
        ProductCategory::factory()->create(['slug' => 'merchandise']);

        $this->actingAs($this->admin())
            ->post(route('admin.product-categories.store'), ['name' => 'Merch', 'slug' => 'merchandise'])
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, ProductCategory::query()->count());
    }

    public function test_a_category_keeps_its_own_slug_on_update(): void
    {
        $category = ProductCategory::factory()->create(['slug' => 'merchandise']);

        $this->actingAs($this->admin())
            ->patch(route('admin.product-categories.update', $category), ['name' => $category->name, 'slug' => 'merchandise'])
            ->assertSessionHasNoErrors();
    }
}
