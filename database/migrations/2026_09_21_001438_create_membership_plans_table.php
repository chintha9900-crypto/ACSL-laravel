<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renewal pricing per category — docs/database/04_MEMBERSHIP_SCHEMA.md §4.
     * The introductory term has no plan. Fee, currency and length are data
     * supplied by ACI (OD-01, OD-02): there is deliberately no default for
     * `fee_amount` or `currency`, and no rows are created here.
     */
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_category_id')->constrained('membership_categories');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->decimal('fee_amount', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('duration_months');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('active_category_key')->nullable()
                ->virtualAs('IF(is_active = 1, membership_category_id, NULL)');
            $table->timestamps();

            $table->unique('active_category_key');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE membership_plans
                ADD CONSTRAINT membership_plans_fee_positive CHECK (fee_amount > 0),
                ADD CONSTRAINT membership_plans_duration_positive CHECK (duration_months > 0)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
