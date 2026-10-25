<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row e-shop settings (same pattern as membership_settings). The Sri
 * Lanka delivery charge has an explicit "configured" flag separate from the
 * amount: until an administrator deliberately sets it (zero included), it is
 * unset and checkout is blocked — an unset value can never mean free delivery.
 * No row is seeded; there is no default price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eshop_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->boolean('delivery_charge_configured')->default(false);
            $table->decimal('delivery_charge_amount', 12, 2)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE eshop_settings
                ADD CONSTRAINT eshop_settings_singleton CHECK (id = 1),
                ADD CONSTRAINT eshop_settings_delivery_charge_consistent
                    CHECK ((delivery_charge_configured = 1 AND delivery_charge_amount IS NOT NULL AND delivery_charge_amount >= 0)
                        OR (delivery_charge_configured = 0 AND delivery_charge_amount IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('eshop_settings');
    }
};
