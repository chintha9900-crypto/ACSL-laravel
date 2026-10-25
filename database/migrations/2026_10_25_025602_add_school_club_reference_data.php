<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The School Club category. It is free: no membership plan (joining or
 * renewal fee) is created for it; its terms renew free of charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('membership_categories')->insertOrIgnore([
            'code' => 'SC',
            'name' => 'School Club',
            'description' => null,
            'is_active' => true,
            'display_order' => 6,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Removes the category only while nothing references it.
     */
    public function down(): void
    {
        $id = DB::table('membership_categories')->where('code', 'SC')->value('id');

        if ($id !== null
            && ! DB::table('membership_applications')->where('membership_category_id', $id)->exists()
            && ! DB::table('memberships')->where('membership_category_id', $id)->exists()) {
            DB::table('membership_number_sequences')->where('membership_category_id', $id)->delete();
            DB::table('membership_categories')->where('id', $id)->delete();
        }
    }
};
