<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School Club Membership.
 *
 * - Category codes become up to two characters (`SC`).
 * - Membership numbers: `SC` + YY + RR + SSSS for a School Club (10 chars) and
 *   the parent number + `/NNN` for each of its student members (14 chars).
 *   A student shares the parent's category/year/sequence and is told apart by
 *   `student_sequence` (0 for every non-student membership), which joins the
 *   number's unique key; the parent's `last_student_sequence` is the locked
 *   counter that allocates it (never MAX()+1).
 * - A student membership has no application of its own.
 * - Free yearly renewal (`free_renewal` terms): no fee, plan or payment.
 * - Student batches submitted by a School Club representative for review, and
 *   the principal-signed student list stored as a private document.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE membership_categories DROP CHECK membership_categories_code_allowed');
        DB::statement(<<<'SQL'
            ALTER TABLE memberships
                DROP CHECK memberships_number_format,
                DROP CHECK memberships_number_matches_components
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE membership_terms
                DROP CHECK membership_terms_term_kind_allowed,
                DROP CHECK membership_terms_free_iff_introductory,
                DROP CHECK membership_terms_fee_and_plan_by_kind
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE documents
                DROP CHECK documents_kind_allowed,
                DROP CHECK documents_single_owner_matches_kind
            SQL);

        Schema::table('membership_categories', function (Blueprint $table) {
            $table->string('code', 2)->charset('ascii')->collation('ascii_bin')->change();
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropForeign(['membership_application_id']);
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('membership_application_id')->nullable()->change();
            $table->foreign('membership_application_id')->references('id')->on('membership_applications');
            $table->string('membership_number', 20)->charset('ascii')->collation('ascii_bin')->change();
            $table->foreignId('parent_membership_id')->nullable()->after('membership_category_id')->constrained('memberships');
            $table->unsignedSmallInteger('student_sequence')->default(0)->after('number_sequence');
            $table->unsignedSmallInteger('last_student_sequence')->default(0)->after('student_sequence');
            // Added before the old key is dropped: it also starts with
            // membership_category_id, so it keeps backing that foreign key.
            $table->unique(
                ['membership_category_id', 'number_year', 'number_sequence', 'student_sequence'],
                'memberships_category_year_sequence_student_unique'
            );
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique('memberships_category_year_sequence_unique');
        });

        Schema::create('school_club_student_batches', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->foreignId('membership_id')->constrained('memberships');
            $table->foreignId('submitted_by_user_id')->constrained('users');
            $table->string('status', 30)->charset('ascii')->collation('ascii_bin')->default('submitted');
            $table->unsignedSmallInteger('students_count');
            $table->timestamp('submitted_at');
            $table->text('request_message')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users');
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['membership_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        Schema::create('school_club_student_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_club_student_batch_id')->constrained('school_club_student_batches');
            $table->string('full_name', 160);
            $table->string('email');
            $table->foreignId('membership_id')->nullable()->unique()->constrained('memberships');
            $table->timestamps();

            $table->unique(['school_club_student_batch_id', 'email'], 'sc_student_entries_batch_email_unique');
            $table->index('email');
        });

        Schema::table('memberships', function (Blueprint $table) {
            $table->foreignId('school_club_student_batch_id')->nullable()->after('parent_membership_id')->constrained('school_club_student_batches');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('school_club_student_batch_id')->nullable()->after('job_application_id')->constrained('school_club_student_batches');
        });

        DB::statement("ALTER TABLE membership_categories ADD CONSTRAINT membership_categories_code_allowed CHECK (code IN ('S', 'P', 'V', 'E', 'C', 'SC'))");

        DB::statement(<<<'SQL'
            ALTER TABLE school_club_student_batches
                ADD CONSTRAINT school_club_student_batches_status_allowed
                    CHECK (status IN ('submitted', 'more_details_required', 'approved', 'rejected')),
                ADD CONSTRAINT school_club_student_batches_decision_consistent
                    CHECK ((status IN ('approved', 'rejected')) = (decided_at IS NOT NULL AND decided_by_user_id IS NOT NULL)),
                ADD CONSTRAINT school_club_student_batches_count_range
                    CHECK (students_count BETWEEN 1 AND 999)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE memberships
                ADD CONSTRAINT memberships_number_format
                    CHECK (membership_number REGEXP '^(SC|[SPVEC])[0-9]{8}(/[0-9]{3})?$'),
                ADD CONSTRAINT memberships_number_matches_components
                    CHECK (
                        SUBSTRING(membership_number, IF(LEFT(membership_number, 2) = 'SC', 3, 2), 2) = LPAD(number_year MOD 100, 2, '0')
                        AND SUBSTRING(membership_number, IF(LEFT(membership_number, 2) = 'SC', 7, 6), 4) = LPAD(number_sequence, 4, '0')
                        AND (
                            (student_sequence = 0 AND LOCATE('/', membership_number) = 0)
                            OR (
                                student_sequence BETWEEN 1 AND 999
                                AND SUBSTRING(membership_number, -4, 1) = '/'
                                AND SUBSTRING(membership_number, -3) = LPAD(student_sequence, 3, '0')
                            )
                        )
                    ),
                ADD CONSTRAINT memberships_student_has_parent
                    CHECK ((parent_membership_id IS NULL) = (student_sequence = 0)),
                ADD CONSTRAINT memberships_last_student_sequence_range
                    CHECK (last_student_sequence BETWEEN 0 AND 999)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE membership_terms
                ADD CONSTRAINT membership_terms_term_kind_allowed
                    CHECK (term_kind IN ('introductory', 'renewal', 'free_renewal')),
                ADD CONSTRAINT membership_terms_free_iff_introductory
                    CHECK ((term_kind IN ('introductory', 'free_renewal')) = (payment_status = 'payment_not_required')),
                ADD CONSTRAINT membership_terms_fee_and_plan_by_kind
                    CHECK (
                        (term_kind IN ('introductory', 'free_renewal') AND fee_amount IS NULL AND fee_currency IS NULL AND membership_plan_id IS NULL)
                        OR (term_kind = 'renewal' AND fee_amount > 0 AND fee_currency IS NOT NULL AND membership_plan_id IS NOT NULL)
                    )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE documents
                ADD CONSTRAINT documents_kind_allowed
                    CHECK (kind IN ('aviation_proof', 'payment_evidence', 'job_application_document', 'school_club_student_list')),
                ADD CONSTRAINT documents_single_owner_matches_kind
                    CHECK (
                        (kind = 'aviation_proof' AND membership_application_id IS NOT NULL AND payment_id IS NULL AND job_application_id IS NULL AND school_club_student_batch_id IS NULL)
                        OR (kind = 'payment_evidence' AND payment_id IS NOT NULL AND membership_application_id IS NULL AND job_application_id IS NULL AND school_club_student_batch_id IS NULL)
                        OR (kind = 'job_application_document' AND job_application_id IS NOT NULL AND membership_application_id IS NULL AND payment_id IS NULL AND school_club_student_batch_id IS NULL)
                        OR (kind = 'school_club_student_list' AND school_club_student_batch_id IS NOT NULL AND membership_application_id IS NULL AND payment_id IS NULL AND job_application_id IS NULL)
                    )
            SQL);
    }

    /**
     * Reversible only while no School Club data exists (no SC numbers, student
     * memberships, free renewals or student-list documents).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE membership_categories DROP CHECK membership_categories_code_allowed');
        DB::statement('ALTER TABLE memberships DROP CHECK memberships_number_format, DROP CHECK memberships_number_matches_components, DROP CHECK memberships_student_has_parent, DROP CHECK memberships_last_student_sequence_range');
        DB::statement('ALTER TABLE membership_terms DROP CHECK membership_terms_term_kind_allowed, DROP CHECK membership_terms_free_iff_introductory, DROP CHECK membership_terms_fee_and_plan_by_kind');
        DB::statement('ALTER TABLE documents DROP CHECK documents_kind_allowed, DROP CHECK documents_single_owner_matches_kind');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_club_student_batch_id');
        });
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_club_student_batch_id');
        });
        Schema::dropIfExists('school_club_student_entries');
        Schema::dropIfExists('school_club_student_batches');

        Schema::table('memberships', function (Blueprint $table) {
            $table->unique(['membership_category_id', 'number_year', 'number_sequence'], 'memberships_category_year_sequence_unique');
        });
        Schema::table('memberships', function (Blueprint $table) {
            $table->dropUnique('memberships_category_year_sequence_student_unique');
            $table->dropConstrainedForeignId('parent_membership_id');
            $table->dropColumn(['student_sequence', 'last_student_sequence']);
            $table->dropForeign(['membership_application_id']);
        });
        Schema::table('memberships', function (Blueprint $table) {
            $table->unsignedBigInteger('membership_application_id')->nullable(false)->change();
            $table->foreign('membership_application_id')->references('id')->on('membership_applications');
            $table->char('membership_number', 9)->charset('ascii')->collation('ascii_bin')->change();
        });
        Schema::table('membership_categories', function (Blueprint $table) {
            $table->char('code', 1)->charset('ascii')->collation('ascii_bin')->change();
        });

        DB::statement("ALTER TABLE membership_categories ADD CONSTRAINT membership_categories_code_allowed CHECK (code IN ('S', 'P', 'V', 'E', 'C'))");
        DB::statement(<<<'SQL'
            ALTER TABLE memberships
                ADD CONSTRAINT memberships_number_format CHECK (membership_number REGEXP '^[SPVEC][0-9]{8}$'),
                ADD CONSTRAINT memberships_number_matches_components
                    CHECK (
                        SUBSTRING(membership_number, 2, 2) = LPAD(number_year MOD 100, 2, '0')
                        AND SUBSTRING(membership_number, 6, 4) = LPAD(number_sequence, 4, '0')
                    )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE membership_terms
                ADD CONSTRAINT membership_terms_term_kind_allowed CHECK (term_kind IN ('introductory', 'renewal')),
                ADD CONSTRAINT membership_terms_free_iff_introductory CHECK ((term_kind = 'introductory') = (payment_status = 'payment_not_required')),
                ADD CONSTRAINT membership_terms_fee_and_plan_by_kind
                    CHECK (
                        (term_kind = 'introductory' AND fee_amount IS NULL AND fee_currency IS NULL AND membership_plan_id IS NULL)
                        OR (term_kind = 'renewal' AND fee_amount > 0 AND fee_currency IS NOT NULL AND membership_plan_id IS NOT NULL)
                    )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE documents
                ADD CONSTRAINT documents_kind_allowed CHECK (kind IN ('aviation_proof', 'payment_evidence', 'job_application_document')),
                ADD CONSTRAINT documents_single_owner_matches_kind
                    CHECK (
                        (kind = 'aviation_proof' AND membership_application_id IS NOT NULL AND payment_id IS NULL AND job_application_id IS NULL)
                        OR (kind = 'payment_evidence' AND payment_id IS NOT NULL AND membership_application_id IS NULL AND job_application_id IS NULL)
                        OR (kind = 'job_application_document' AND job_application_id IS NOT NULL AND membership_application_id IS NULL AND payment_id IS NULL)
                    )
            SQL);
    }
};
