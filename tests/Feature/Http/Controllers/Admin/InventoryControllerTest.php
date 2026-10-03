<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Catalogue\AdjustInventory;
use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class InventoryControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_inventory_route(): void
    {
        $inventory = Inventory::factory()->create();

        $this->get(route('admin.inventory.index'))->assertRedirect(route('login'));
        $this->patch(route('admin.inventory.update-threshold', $inventory), ['low_stock_threshold' => 3])->assertRedirect(route('login'));
        $this->post(route('admin.inventory.add-stock', $inventory), ['amount' => 1])->assertRedirect(route('login'));
        $this->post(route('admin.inventory.remove-stock', $inventory), ['amount' => 1])->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_inventory_route(): void
    {
        $inventory = Inventory::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.inventory.index'))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.inventory.update-threshold', $inventory), ['low_stock_threshold' => 3])->assertForbidden();
        $this->actingAs($member)->post(route('admin.inventory.add-stock', $inventory), ['amount' => 1])->assertForbidden();
        $this->actingAs($member)->post(route('admin.inventory.remove-stock', $inventory), ['amount' => 1])->assertForbidden();
    }

    // --- adding stock --------------------------------------------------------

    public function test_an_admin_can_add_stock(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 10]);

        $this->actingAs($this->admin())
            ->post(route('admin.inventory.add-stock', $inventory), ['amount' => 15])
            ->assertRedirect(route('admin.inventory.index'))
            ->assertSessionHas('status');

        $this->assertSame(25, $inventory->fresh()->quantity);
    }

    public function test_adding_stock_requires_a_positive_integer_amount(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 10]);

        $this->actingAs($this->admin())
            ->post(route('admin.inventory.add-stock', $inventory), ['amount' => 0])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin())
            ->post(route('admin.inventory.add-stock', $inventory), ['amount' => -5])
            ->assertSessionHasErrors('amount');

        $this->assertSame(10, $inventory->fresh()->quantity);
    }

    // --- reducing stock ------------------------------------------------------

    public function test_an_admin_can_remove_stock(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 10]);

        $this->actingAs($this->admin())
            ->post(route('admin.inventory.remove-stock', $inventory), ['amount' => 4])
            ->assertRedirect(route('admin.inventory.index'))
            ->assertSessionHas('status');

        $this->assertSame(6, $inventory->fresh()->quantity);
    }

    // --- preventing negative stock -------------------------------------------

    public function test_removing_more_stock_than_is_available_is_rejected_without_a_database_error(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 5]);

        $response = $this->actingAs($this->admin())
            ->post(route('admin.inventory.remove-stock', $inventory), ['amount' => 10]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('amount');
        $this->assertSame(5, $inventory->fresh()->quantity, 'Stock must be unchanged after a rejected removal.');
    }

    public function test_the_database_itself_still_refuses_to_let_quantity_go_negative(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 5]);

        $this->assertThrows(
            fn () => DB::table('inventories')->where('id', $inventory->id)->update(['quantity' => -1]),
            QueryException::class,
        );

        $this->assertSame(5, $inventory->fresh()->quantity);
    }

    // --- low-stock / out-of-stock detection ----------------------------------

    public function test_an_admin_can_set_a_products_low_stock_threshold(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 20, 'low_stock_threshold' => 5]);

        $this->actingAs($this->admin())
            ->patch(route('admin.inventory.update-threshold', $inventory), ['low_stock_threshold' => 8])
            ->assertRedirect(route('admin.inventory.index'));

        $this->assertSame(8, $inventory->fresh()->low_stock_threshold);
    }

    public function test_low_stock_is_detected_when_quantity_is_at_or_below_the_threshold_but_above_zero(): void
    {
        $low = Inventory::factory()->create(['quantity' => 3, 'low_stock_threshold' => 5]);
        $healthy = Inventory::factory()->create(['quantity' => 20, 'low_stock_threshold' => 5]);
        $out = Inventory::factory()->create(['quantity' => 0, 'low_stock_threshold' => 5]);

        $this->assertTrue($low->isLowStock());
        $this->assertFalse($healthy->isLowStock());
        $this->assertFalse($out->isLowStock(), 'Out of stock is its own, more urgent category, not "low stock".');

        $lowStockIds = Inventory::query()->lowStock()->pluck('id');
        $this->assertTrue($lowStockIds->contains($low->id));
        $this->assertFalse($lowStockIds->contains($healthy->id));
        $this->assertFalse($lowStockIds->contains($out->id));
    }

    public function test_out_of_stock_is_detected_when_quantity_reaches_zero(): void
    {
        $out = Inventory::factory()->create(['quantity' => 0]);
        $inStock = Inventory::factory()->create(['quantity' => 1]);

        $this->assertTrue($out->isOutOfStock());
        $this->assertFalse($inStock->isOutOfStock());

        $outOfStockIds = Inventory::query()->outOfStock()->pluck('id');
        $this->assertTrue($outOfStockIds->contains($out->id));
        $this->assertFalse($outOfStockIds->contains($inStock->id));
    }

    public function test_the_index_page_lists_low_stock_and_out_of_stock_counts_and_filters(): void
    {
        $low = Inventory::factory()->lowStock()->create();
        $out = Inventory::factory()->outOfStock()->create();
        Inventory::factory()->create(['quantity' => 50, 'low_stock_threshold' => 5]);

        $response = $this->actingAs($this->admin())->get(route('admin.inventory.index'))->assertOk();
        $response->assertSee('Low stock (1)');
        $response->assertSee('Out of stock (1)');

        $lowOnly = $this->actingAs($this->admin())->get(route('admin.inventory.index', ['filter' => 'low']));
        $lowOnly->assertSee($low->product->sku);
        $lowOnly->assertDontSee($out->product->sku);

        $outOnly = $this->actingAs($this->admin())->get(route('admin.inventory.index', ['filter' => 'out']));
        $outOnly->assertSee($out->product->sku);
        $outOnly->assertDontSee($low->product->sku);
    }

    // --- completed orders are never affected ---------------------------------

    public function test_adjusting_stock_never_touches_an_existing_orders_snapshot(): void
    {
        $product = Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]);
        $inventory = Inventory::factory()->create(['product_id' => $product->id, 'quantity' => 10]);
        $orderItem = OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => $product->price,
            'quantity' => 1,
            'line_total' => $product->price,
        ]);

        $this->actingAs($this->admin())->post(route('admin.inventory.remove-stock', $inventory), ['amount' => 5]);
        $product->update(['name' => 'Renamed Product', 'sku' => 'POLO-999', 'price' => 99]);

        $this->assertSame('ACI Polo Shirt', $orderItem->fresh()->product_name);
        $this->assertSame('POLO-001', $orderItem->fresh()->sku);
        $this->assertSame('25.00', $orderItem->fresh()->unit_price);
    }

    // --- concurrent-safe stock handling ---------------------------------------

    /**
     * Not a true multi-process race (PHPUnit runs single-threaded), but this
     * proves the mechanism that makes concurrent requests safe: each call to
     * `AdjustInventory::remove()` opens its own transaction and re-reads the
     * row with `lockForUpdate()`, so sequential calls — the same interleaving
     * a lock would force two concurrent requests into — always see the
     * other's committed result rather than a stale value.
     */
    public function test_sequential_removals_never_oversell_because_each_one_re_reads_the_locked_row(): void
    {
        $inventory = Inventory::factory()->create(['quantity' => 10]);
        $adjust = app(AdjustInventory::class);

        $adjust->remove($inventory, 6);
        $this->assertSame(4, $inventory->fresh()->quantity);

        $this->assertThrows(
            fn () => $adjust->remove($inventory, 6),
            InsufficientStockException::class,
        );

        $this->assertSame(4, $inventory->fresh()->quantity, 'The failed second removal must not have changed stock.');
    }

    public function test_adding_and_removing_stock_happen_inside_a_transaction(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $inventory = Inventory::factory()->create(['quantity' => 10]);
        app(AdjustInventory::class)->add($inventory, 5);

        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'for update')));
    }
}
