<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One line per product per cart (`UNIQUE(cart_id, product_id)`); adding
     * the same product again should update the existing line's quantity,
     * not insert a second one — an application rule for a later step, this
     * constraint just makes violating it impossible. `RESTRICT` on
     * `product_id`: a product cannot be deleted while it still sits in
     * someone's cart (docs/database/12 §3.2).
     */
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'product_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cart_items
                ADD CONSTRAINT cart_items_quantity_at_least_one CHECK (quantity >= 1)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
