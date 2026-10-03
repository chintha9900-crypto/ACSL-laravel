<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-Shop Step 1 — database foundation only. `public_id` (ULID) is the
     * link-safe identifier, the same convention `payments.public_id` already
     * uses; `order_number` is the separate human-readable business
     * identifier shown to customers (never a key) — generating it is a
     * later (checkout) step, this migration only guarantees uniqueness.
     * `user_id` is nullable: guests may purchase PUBLIC products, so
     * `customer_name`/`customer_email`/`customer_phone` are always captured
     * as their own snapshot regardless of whether an account exists.
     *
     * Discounts/shipping/tax are deliberately not modelled yet (no
     * `discount_amount`/`shipping_amount`/`coupon_id` columns) — nothing in
     * this step builds coupons or shipping methods, and adding them later is
     * an additive migration, not a breaking one.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('order_number', 20)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('customer_name', 150);
            $table->string('customer_email', 255);
            $table->string('customer_phone', 40)->nullable();
            $table->string('status', 30)->default('pending_payment');
            $table->char('currency', 3);
            $table->decimal('total_amount', 12, 2);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('customer_email');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE orders
                ADD CONSTRAINT orders_total_amount_non_negative CHECK (total_amount >= 0),
                ADD CONSTRAINT orders_status_allowed
                    CHECK (status IN ('pending_payment', 'paid', 'processing', 'packed', 'shipped', 'delivered', 'cancelled', 'refunded'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
