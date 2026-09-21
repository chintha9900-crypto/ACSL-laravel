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
     * Typed singleton (id = 1) — docs/database/04_MEMBERSHIP_SCHEMA.md §5.
     * The row itself is created on demand by the application, not here.
     */
    public function up(): void
    {
        Schema::create('membership_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1)->primary();
            $table->unsignedSmallInteger('introductory_period_months')->default(6);
            $table->unsignedSmallInteger('reapplication_cooldown_days')->default(30);
            $table->unsignedSmallInteger('account_setup_token_ttl_hours')->default(72);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE membership_settings
                ADD CONSTRAINT membership_settings_singleton CHECK (id = 1),
                ADD CONSTRAINT membership_settings_introductory_period_positive CHECK (introductory_period_months > 0)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_settings');
    }
};
