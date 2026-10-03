<?php

namespace Tests\Feature\Models;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\MysqlTestCase;

class InventoryTest extends MysqlTestCase
{
    public function test_a_product_has_one_inventory_row(): void
    {
        $product = Product::factory()->create();
        $inventory = Inventory::factory()->create(['product_id' => $product->id, 'quantity' => 10]);

        $this->assertTrue($product->inventory->is($inventory));
        $this->assertSame($product->id, $inventory->product->id);
    }

    public function test_inventory_quantity_can_never_be_negative(): void
    {
        $this->assertThrows(
            fn () => Inventory::factory()->create(['quantity' => -1]),
            QueryException::class,
        );
    }

    public function test_an_update_that_would_make_inventory_negative_is_rejected(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 5]);

        $this->assertThrows(
            fn () => DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => -1]),
            QueryException::class,
        );

        $this->assertSame(5, $inventory->fresh()->quantity);
    }

    public function test_a_product_can_only_have_one_inventory_row(): void
    {
        $product = Product::factory()->create();
        Inventory::factory()->create(['product_id' => $product->id]);

        $this->assertThrows(
            fn () => Inventory::factory()->create(['product_id' => $product->id]),
            QueryException::class,
        );
    }

    public function test_deleting_a_product_removes_its_inventory_row(): void
    {
        $product = Product::factory()->create();
        $inventory = Inventory::factory()->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertNull(Inventory::find($inventory->id));
    }
}
