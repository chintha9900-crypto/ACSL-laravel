<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Genuine integration defect found during Step 5 end-to-end regression
     * testing: `membership_categories.code` was widened to allow E/C
     * (`2026_09_29_000001_widen_membership_categories_code_check`), but this
     * separate CHECK constraint on `memberships.membership_number` was
     * missed — it still only accepted a leading S, P or V, so activating an
     * Aviation Enthusiast or Corporate application failed at the database
     * layer with "Check constraint 'memberships_number_format' is
     * violated." even though the application code correctly generated an
     * `EYYRRSSSS`/`CYYRRSSSS` number.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE memberships
                DROP CHECK memberships_number_format,
                ADD CONSTRAINT memberships_number_format CHECK (membership_number REGEXP '^[SPVEC][0-9]{8}$')
            SQL);
    }

    /**
     * Reverse the migrations.
     *
     * Narrowing back to S/P/V will fail here if any E/C membership number
     * already exists — intentional, same reasoning as the
     * `membership_categories` CHECK-widening migration's down().
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE memberships
                DROP CHECK memberships_number_format,
                ADD CONSTRAINT memberships_number_format CHECK (membership_number REGEXP '^[SPV][0-9]{8}$')
            SQL);
    }
};
