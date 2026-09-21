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
     * One row per application attempt for new membership —
     * docs/database/04_MEMBERSHIP_SCHEMA.md §6. Never overwritten or deleted.
     */
    public function up(): void
    {
        Schema::create('membership_applications', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->foreignId('membership_category_id')->constrained('membership_categories');
            $table->string('status', 30)->charset('ascii')->collation('ascii_bin')->default('submitted');
            $table->string('full_name', 160);
            $table->string('email');
            $table->string('mobile', 40);
            $table->string('address', 400);
            $table->string('aviation_role', 160);
            $table->string('aviation_organisation', 200);
            $table->date('study_start_date')->nullable();
            $table->date('expected_completion_date')->nullable();
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->text('previous_employers')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('proof_reviewed_at')->nullable();
            $table->foreignId('proof_reviewed_by_user_id')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users');
            $table->text('decision_note')->nullable();
            $table->string('open_email_key')->nullable()
                ->virtualAs("IF(status IN ('submitted', 'more_details_required'), email, NULL)");
            $table->timestamps();

            $table->unique('open_email_key');
            $table->index(['status', 'submitted_at']);
            $table->index(['email', 'status', 'decided_at']);
            $table->index(['mobile', 'status', 'decided_at']);
            $table->index(['user_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE membership_applications
                ADD CONSTRAINT membership_applications_status_allowed
                    CHECK (status IN ('submitted', 'more_details_required', 'approved', 'rejected')),
                ADD CONSTRAINT membership_applications_decision_consistent
                    CHECK ((status IN ('approved', 'rejected')) = (decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL)),
                ADD CONSTRAINT membership_applications_approved_requires_proof_review
                    CHECK (status <> 'approved' OR proof_reviewed_at IS NOT NULL),
                ADD CONSTRAINT membership_applications_study_dates_ordered
                    CHECK (expected_completion_date IS NULL OR study_start_date IS NULL OR expected_completion_date >= study_start_date)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_applications');
    }
};
