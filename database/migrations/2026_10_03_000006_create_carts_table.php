<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cart belongs to exactly one registered member (`UNIQUE(user_id)`,
     * NULLs allowed so guests can each have their own) or to a guest via
     * `guest_token` — never neither (docs/database/12 §3.1). `RESTRICT` on
     * `user_id`: users are never hard-deleted in this app, so this never
     * actually fires, but it keeps the "user or guest" CHECK meaningful
     * rather than relying on a SET NULL side effect. Carts hold no prices —
     * they are re-derived at checkout, which this step does not build.
     */
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->char('guest_token', 40)->charset('ascii')->collation('ascii_bin')->nullable()->unique();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE carts
                ADD CONSTRAINT carts_user_or_guest CHECK (user_id IS NOT NULL OR guest_token IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
