<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One inventory row per product (1:1) — a plain on-hand count, not the
     * full signed ledger the earlier draft design describes
     * (docs/database/12 §2 `inventory_transactions`): that ledger exists to
     * support reservations/returns/adjustments once checkout is built, which
     * this step deliberately excludes. "Inventory must never become
     * negative" is enforced here directly by a CHECK constraint.
     */
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE inventories
                ADD CONSTRAINT inventories_quantity_non_negative CHECK (quantity >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
