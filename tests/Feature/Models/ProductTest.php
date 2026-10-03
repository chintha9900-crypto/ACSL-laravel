<?php

namespace Tests\Feature\Models;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\MysqlTestCase;

class ProductTest extends MysqlTestCase
{
    // --- category relationship ---------------------------------------------

    public function test_a_product_belongs_to_a_category(): void
    {
        $category = ProductCategory::factory()->create(['name' => 'Merchandise']);
        $product = Product::factory()->create(['product_category_id' => $category->id]);

        $this->assertTrue($product->category->is($category));
        $this->assertTrue($category->products->contains($product));
    }

    public function test_a_category_cannot_be_deleted_while_it_still_has_products(): void
    {
        $category = ProductCategory::factory()->create();
        Product::factory()->create(['product_category_id' => $category->id]);

        $this->assertThrows(fn () => $category->delete(), QueryException::class);
    }

    // --- unique SKU ----------------------------------------------------------

    public function test_a_products_sku_must_be_unique(): void
    {
        Product::factory()->create(['sku' => 'ACI-SHOP-001']);

        $this->assertThrows(
            fn () => Product::factory()->create(['sku' => 'ACI-SHOP-001']),
            QueryException::class,
        );
    }

    // --- valid pricing ---------------------------------------------------------

    public function test_a_products_price_cannot_be_negative(): void
    {
        $this->assertThrows(
            fn () => Product::factory()->create(['price' => -1]),
            QueryException::class,
        );
    }

    public function test_a_product_can_have_a_zero_price(): void
    {
        $product = Product::factory()->create(['price' => 0]);

        $this->assertSame('0.00', $product->price);
    }

    // --- access type -----------------------------------------------------------

    public function test_a_products_access_type_must_be_a_recognised_value(): void
    {
        $this->assertThrows(
            fn () => Product::factory()->create(['access_type' => 'SECRET']),
            QueryException::class,
        );
    }

    public function test_a_public_product_is_accessible_to_anyone(): void
    {
        $product = Product::factory()->create(['access_type' => Product::ACCESS_PUBLIC]);
        $activeMember = User::factory()->active()->create();

        $this->assertTrue($product->isAccessibleTo(null));
        $this->assertTrue($product->isAccessibleTo($activeMember));
    }

    public function test_a_member_only_product_is_hidden_from_guests_and_inactive_accounts(): void
    {
        $product = Product::factory()->memberOnly()->create();
        $pendingMember = User::factory()->create(['status' => 'pending_setup']);

        $this->assertFalse($product->isAccessibleTo(null));
        $this->assertFalse($product->isAccessibleTo($pendingMember));
    }

    public function test_a_member_only_product_is_accessible_to_an_authenticated_active_member(): void
    {
        $product = Product::factory()->memberOnly()->create();
        $activeMember = User::factory()->active()->create();

        $this->assertTrue($product->isAccessibleTo($activeMember));
    }
}
