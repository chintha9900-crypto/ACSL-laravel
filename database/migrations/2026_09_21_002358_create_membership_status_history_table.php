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
     * Append-only lifecycle trail — docs/database/04_MEMBERSHIP_SCHEMA.md §11.
     * Not polymorphic: events anchor to an application (before activation) or a
     * member (after), with an optional term. `event` is an open vocabulary (no
     * CHECK); the no-update/no-delete rule (R-12) is enforced in the application.
     */
    public function up(): void
    {
        Schema::create('membership_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_application_id')->nullable()->constrained('membership_applications');
            $table->foreignId('membership_id')->nullable()->constrained('memberships');
            $table->foreignId('membership_term_id')->nullable()->constrained('membership_terms');
            $table->string('event', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->string('actor_type', 20)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('actor_user_id')->nullable()->constrained('users');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['membership_application_id', 'id']);
            $table->index(['membership_id', 'id']);
            $table->index(['event', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE membership_status_history
                ADD CONSTRAINT membership_status_history_actor_type_allowed
                    CHECK (actor_type IN ('applicant', 'admin', 'member', 'system')),
                ADD CONSTRAINT membership_status_history_has_anchor
                    CHECK (membership_application_id IS NOT NULL OR membership_id IS NOT NULL)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_status_history');
    }
};
