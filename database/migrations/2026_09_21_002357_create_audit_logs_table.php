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
     * Cross-domain "who did what" log — docs/database/09_AUDIT_SCHEMA.md §1.
     * Append-only (no `updated_at`; the no-update/no-delete rule is enforced in
     * the application). `subject_type`/`subject_id` are the one approved
     * polymorphic pair and carry no foreign key. `event` is an open vocabulary
     * (no CHECK). No retention columns: retention is unresolved (OD-11).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('actor_type', 20)->charset('ascii')->collation('ascii_bin')->default('user');
            $table->string('event', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id', 'id'], 'audit_logs_subject_index');
            $table->index(['user_id', 'created_at']);
            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_logs
                ADD CONSTRAINT audit_logs_actor_type_allowed
                    CHECK (actor_type IN ('user', 'system', 'guest')),
                ADD CONSTRAINT audit_logs_subject_pair_consistent
                    CHECK ((subject_type IS NULL) = (subject_id IS NULL)),
                ADD CONSTRAINT audit_logs_user_actor_has_user
                    CHECK (actor_type <> 'user' OR user_id IS NOT NULL)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
