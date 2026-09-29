<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Introductory period, all categories, 6 months → 3 months (approved
     * Architecture/Database Design change). Changes only the column
     * default and the existing singleton row's current value — it never
     * touches `membership_terms`. Every term snapshots its own
     * `duration_months`/`expires_on` at activation time (docs/database/04
     * §5: "Changing it affects only future activations"), so no already
     * activated membership's expiry date changes.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE membership_settings MODIFY introductory_period_months SMALLINT UNSIGNED NOT NULL DEFAULT 3');

        DB::table('membership_settings')->where('id', 1)->update(['introductory_period_months' => 3]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE membership_settings MODIFY introductory_period_months SMALLINT UNSIGNED NOT NULL DEFAULT 6');

        DB::table('membership_settings')->where('id', 1)->update(['introductory_period_months' => 6]);
    }
};
