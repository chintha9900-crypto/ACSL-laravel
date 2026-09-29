<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The two new categories and their one active renewal plan each
     * (approved Architecture/Database Design change). Category/plan rows
     * are reference data, not application logic — the same status as the
     * pre-existing Student/Professional/Veteran rows, which were never
     * migration- or seeder-created either (docs/database/04 §3's own
     * docblock: "Rows are data, not created here"). This migration is the
     * one place that changes for this project, since it is the
     * reproducible, reviewable record of the two new rows being added.
     *
     * `insertOrIgnore` throughout: safe to run against a database that
     * already has these rows (e.g. a re-run), and never touches any
     * existing S/P/V category, plan, application or term.
     */
    public function up(): void
    {
        DB::table('membership_categories')->insertOrIgnore([
            [
                'code' => 'E',
                'name' => 'Aviation Enthusiast',
                'description' => null,
                'is_active' => true,
                'display_order' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'C',
                'name' => 'Corporate',
                'description' => null,
                'is_active' => true,
                'display_order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $enthusiastId = DB::table('membership_categories')->where('code', 'E')->value('id');
        $corporateId = DB::table('membership_categories')->where('code', 'C')->value('id');

        DB::table('membership_plans')->insertOrIgnore([
            [
                'membership_category_id' => $enthusiastId,
                'name' => 'Aviation Enthusiast — annual renewal',
                'description' => null,
                'fee_amount' => 2000.00,
                'currency' => 'LKR',
                'duration_months' => 12,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'membership_category_id' => $corporateId,
                'name' => 'Corporate — annual renewal',
                'description' => null,
                'fee_amount' => 30000.00,
                'currency' => 'LKR',
                'duration_months' => 12,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * Fails (by FK RESTRICT) if any application, plan reference, or
     * membership already exists against these categories — intentional,
     * same reasoning as the CHECK-constraint migration's down().
     */
    public function down(): void
    {
        DB::table('membership_plans')
            ->whereIn('membership_category_id', function ($query) {
                $query->select('id')->from('membership_categories')->whereIn('code', ['E', 'C']);
            })
            ->delete();

        DB::table('membership_categories')->whereIn('code', ['E', 'C'])->delete();
    }
};
