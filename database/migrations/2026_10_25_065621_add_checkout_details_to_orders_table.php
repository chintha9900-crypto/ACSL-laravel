<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Snapshots the delivery address and chosen payment method on
 * the order itself (docs/architecture/09 §5: an order's address never follows
 * a later profile edit). Every new column is nullable or defaulted, so orders
 * placed before this migration stay valid unchanged.
 *
 * - payment_method: the method chosen at checkout (or on a later retry).
 * - is_test: set for simulated (dummy) orders so they are always marked.
 * - stock_released_at: set once when an unpaid order's reserved stock is
 *   returned to inventory, so the release can never happen twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 30)->charset('ascii')->collation('ascii_bin')->nullable()->after('customer_phone');
            $table->string('delivery_address_line1', 191)->nullable()->after('payment_method');
            $table->string('delivery_address_line2', 191)->nullable()->after('delivery_address_line1');
            $table->string('delivery_city', 100)->nullable()->after('delivery_address_line2');
            $table->string('delivery_postcode', 20)->nullable()->after('delivery_city');
            $table->char('delivery_country', 2)->charset('ascii')->collation('ascii_bin')->nullable()->after('delivery_postcode');
            $table->boolean('is_test')->default(false)->after('total_amount');
            $table->timestamp('stock_released_at')->nullable()->after('is_test');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_payment_method_allowed
                    CHECK (payment_method IS NULL OR payment_method IN ('bank_transfer', 'payhere', 'dummy'))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CHECK orders_payment_method_allowed');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'delivery_address_line1',
                'delivery_address_line2',
                'delivery_city',
                'delivery_postcode',
                'delivery_country',
                'is_test',
                'stock_released_at',
            ]);
        });
    }
};
