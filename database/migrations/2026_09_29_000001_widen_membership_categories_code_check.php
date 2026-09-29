<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Widens `membership_categories.code` from the originally-confirmed
     * three (S, P, V) to five: adds Aviation Enthusiast (E) and Corporate
     * (C) — approved Architecture/Database Design change, docs/database/04
     * §3. No column type change; `CHAR(1)` already fits both new letters.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE membership_categories
                DROP CHECK membership_categories_code_allowed,
                ADD CONSTRAINT membership_categories_code_allowed CHECK (code IN ('S', 'P', 'V', 'E', 'C'))
            SQL);
    }

    /**
     * Reverse the migrations.
     *
     * Narrowing back to S/P/V will fail here if any E/C category row still
     * exists — intentional: a rollback must not silently strand
     * incompatible data.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE membership_categories
                DROP CHECK membership_categories_code_allowed,
                ADD CONSTRAINT membership_categories_code_allowed CHECK (code IN ('S', 'P', 'V'))
            SQL);
    }
};
