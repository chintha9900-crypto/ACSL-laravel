<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Restore the original Student, Professional and Veteran
     * membership category reference records.
     *
     * insertOrIgnore keeps this migration safe when a category
     * already exists in the database.
     */
    public function up(): void
    {
        $now = now();

        DB::table('membership_categories')->insertOrIgnore([
            [
                'code' => 'S',
                'name' => 'Student',
                'description' => null,
                'is_active' => true,
                'display_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'P',
                'name' => 'Professional',
                'description' => null,
                'is_active' => true,
                'display_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'V',
                'name' => 'Veteran',
                'description' => null,
                'is_active' => true,
                'display_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Intentionally do not delete these reference records on rollback.
     * They may have existed before this migration or may now be
     * referenced by membership applications and other records.
     */
    public function down(): void
    {
        // Intentionally left blank to prevent accidental data loss.
    }
};