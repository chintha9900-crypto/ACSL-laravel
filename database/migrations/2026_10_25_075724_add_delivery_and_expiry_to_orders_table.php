<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive. Snapshots the delivery charge and item subtotal on each order
 * (total_amount = subtotal_amount + delivery_charge_amount), so a later
 * settings change never alters an existing order. Older orders keep NULLs.
 *
 * expires_at is the payment deadline, fixed from the order's creation time
 * (30 minutes for PayHere, 72 hours for bank transfer); the scheduled
 * `orders:expire-unpaid` command moves overdue unpaid orders to the new
 * `expired` status and returns their stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('subtotal_amount', 12, 2)->nullable()->after('currency');
            $table->decimal('delivery_charge_amount', 12, 2)->nullable()->after('subtotal_amount');
            $table->timestamp('expires_at')->nullable()->after('stock_released_at');
            $table->index(['status', 'expires_at']);
        });

        DB::statement('ALTER TABLE orders DROP CHECK orders_status_allowed');
        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_status_allowed
                    CHECK (status IN ('pending_payment', 'paid', 'processing', 'packed', 'shipped', 'delivered', 'cancelled', 'refunded', 'expired')),
                ADD CONSTRAINT orders_delivery_charge_non_negative
                    CHECK (delivery_charge_amount IS NULL OR delivery_charge_amount >= 0),
                ADD CONSTRAINT orders_subtotal_non_negative
                    CHECK (subtotal_amount IS NULL OR subtotal_amount >= 0)
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK orders_delivery_charge_non_negative');
        DB::statement('ALTER TABLE orders DROP CHECK orders_subtotal_non_negative');
        DB::statement('ALTER TABLE orders DROP CHECK orders_status_allowed');
        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_status_allowed
                    CHECK (status IN ('pending_payment', 'paid', 'processing', 'packed', 'shipped', 'delivered', 'cancelled', 'refunded'))
            SQL);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['subtotal_amount', 'delivery_charge_amount', 'expires_at']);
        });
    }
};
