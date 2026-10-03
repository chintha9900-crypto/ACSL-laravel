<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Completes `payments.order_id`, prepared by
     * `2026_09_21_001442_create_payments_table` specifically for this
     * moment ("the foreign key is added by the migration that creates
     * `orders`"). `payments` is already a provider-independent ledger that
     * can target either a membership term or an order
     * (`payments_exactly_one_purpose` CHECK) and already allows an
     * order-appropriate status vocabulary — so "payment/order status data"
     * (E-Shop Step 1, item 9) is this one foreign key, not a new table.
     * `orders.status` (order lifecycle) and `payments.status` (payment
     * lifecycle) stay separate vocabularies, as documented in
     * docs/database/12_ECOMMERCE_SCHEMA.md §5.1.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['order_id']);
        });
    }
};
