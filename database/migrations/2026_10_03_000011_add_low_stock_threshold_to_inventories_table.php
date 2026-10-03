<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-Shop Step 3 — inventory management. Per-product low-stock threshold,
     * admin-editable; a quantity at or below it (but above zero) is "low
     * stock", zero is "out of stock" (`Inventory::scopeLowStock()`/
     * `scopeOutOfStock()`).
     */
    public function up(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->default(5)->after('quantity');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventories
                ADD CONSTRAINT inventories_low_stock_threshold_non_negative CHECK (low_stock_threshold >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });
    }
};
