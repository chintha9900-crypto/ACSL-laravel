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
     * One-time account setup tokens — docs/database/03_IDENTITY_SCHEMA.md §3.
     * Only a SHA-256 hash of the token is stored; the plaintext exists only in
     * the link handed to the member. `live_key` is a VIRTUAL generated column
     * whose UNIQUE index allows at most one live (unused, not invalidated)
     * token per (user, purpose). Deferred from T2 until the auth workflow.
     */
    public function up(): void
    {
        Schema::create('account_setup_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('membership_id')->constrained('memberships');
            $table->string('purpose', 30)->charset('ascii')->collation('ascii_bin')->default('account_setup');
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidated_reason', 30)->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users');
            $table->string('live_key', 64)->nullable()
                ->virtualAs("IF(used_at IS NULL AND invalidated_at IS NULL, CONCAT(user_id, ':', purpose), NULL)");
            $table->timestamps();

            $table->unique('live_key');
            $table->index(['user_id', 'purpose']);
            $table->index('expires_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE account_setup_tokens
                ADD CONSTRAINT account_setup_tokens_purpose_allowed
                    CHECK (purpose IN ('account_setup', 'membership_link'))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_setup_tokens');
    }
};
