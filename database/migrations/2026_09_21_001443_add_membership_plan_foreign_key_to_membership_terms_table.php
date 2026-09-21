<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the foreign key that the T2 `membership_terms` migration deferred until
     * its parent existed (docs/database/04_MEMBERSHIP_SCHEMA.md §9). Default
     * NO ACTION, per docs/database/18 A-1; the column already carries an index.
     */
    public function up(): void
    {
        Schema::table('membership_terms', function (Blueprint $table) {
            $table->foreign('membership_plan_id')->references('id')->on('membership_plans');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_terms', function (Blueprint $table) {
            $table->dropForeign(['membership_plan_id']);
        });
    }
};
