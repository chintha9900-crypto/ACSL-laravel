<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-Shop Step 1 — database foundation only. Deliberately simpler than
     * the earlier draft design (docs/database/12_ECOMMERCE_SCHEMA.md §1.2):
     * today's explicit requirement puts price, SKU and inventory directly on
     * the product (no `product_variants` indirection), so that doc's
     * `visibility` column (`public`/`members`) is superseded here by
     * `access_type` (`PUBLIC`/`MEMBER_ONLY`), matching the current
     * instruction's exact wording — flagged for reconciliation with that
     * doc, not silently merged into it.
     *
     * "Products belong to categories" is read as a required relationship
     * (not nullable, `RESTRICT` on delete) — a category with products
     * assigned cannot be deleted until they are reassigned or removed.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('sku', 64)->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('access_type', 20)->default('PUBLIC');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'access_type']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE products
                ADD CONSTRAINT products_price_non_negative CHECK (price >= 0),
                ADD CONSTRAINT products_access_type_allowed CHECK (access_type IN ('PUBLIC', 'MEMBER_ONLY'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
