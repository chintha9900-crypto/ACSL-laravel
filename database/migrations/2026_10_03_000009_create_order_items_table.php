<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historical price preserved (docs/database/12 §5.2): `product_name`,
     * `sku` and `unit_price` are snapshots taken at order placement, never
     * read live from `products` — later edits (or deletion) of the product
     * never change an existing order. `product_id` is therefore nullable
     * with `SET NULL` on delete: the row (and its snapshot) survives even if
     * the product itself is later removed.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name', 255);
            $table->string('sku', 64);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->unique(['order_id', 'product_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_items
                ADD CONSTRAINT order_items_unit_price_non_negative CHECK (unit_price >= 0),
                ADD CONSTRAINT order_items_quantity_at_least_one CHECK (quantity >= 1),
                ADD CONSTRAINT order_items_line_total_matches CHECK (line_total = unit_price * quantity)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
