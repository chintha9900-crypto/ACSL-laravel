<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RBAC foundation. `users.role` was validated against a hardcoded CHECK
     * list (`member`, `admin` only). This replaces that CHECK with a real
     * foreign key into `roles.name` — now also allowing `editor` and `dev` —
     * so the set of valid roles lives in exactly one place (the `roles`
     * table) and adding a future role never requires another migration here.
     * `roles` is seeded by the previous migration before this one runs, so
     * every existing `users.role` value already satisfies the new key.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE users
                DROP CHECK users_role_allowed,
                ADD CONSTRAINT users_role_foreign FOREIGN KEY (role) REFERENCES roles (name)
            SQL);
    }

    /**
     * Reverse the migrations.
     *
     * Restoring the original two-value CHECK will fail here if any user
     * already holds `editor` or `dev` — intentional: a rollback must not
     * silently strand incompatible data.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE users
                DROP FOREIGN KEY users_role_foreign,
                ADD CONSTRAINT users_role_allowed CHECK (role IN ('member', 'admin'))
            SQL);
    }
};
