<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School Club student list workflow (additive only — no existing row changes).
 *
 * - Each student entry keeps the template's First name, Surname and date of
 *   birth. Nullable, because lists submitted before this change only carry
 *   `full_name` (still filled for every entry, and still what approval uses).
 * - A batch may wait in `awaiting_signed_list` between the Excel upload and the
 *   principal-signed PDF; it reaches admin review (`submitted`) only once the
 *   signed PDF is uploaded.
 * - The uploaded Excel is kept as a private document of its own kind,
 *   `school_club_student_spreadsheet`, owned by the batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_club_student_entries', function (Blueprint $table) {
            $table->string('first_name', 80)->nullable()->after('full_name');
            $table->string('surname', 80)->nullable()->after('first_name');
            $table->date('date_of_birth')->nullable()->after('surname');
        });

        DB::statement('ALTER TABLE school_club_student_batches DROP CHECK school_club_student_batches_status_allowed');
        DB::statement("ALTER TABLE school_club_student_batches ADD CONSTRAINT school_club_student_batches_status_allowed
            CHECK (status IN ('awaiting_signed_list', 'submitted', 'more_details_required', 'approved', 'rejected'))");

        DB::statement('ALTER TABLE documents DROP CHECK documents_kind_allowed, DROP CHECK documents_single_owner_matches_kind');
        DB::statement("
            ALTER TABLE documents
                ADD CONSTRAINT documents_kind_allowed
                    CHECK (kind IN ('aviation_proof', 'payment_evidence', 'job_application_document', 'school_club_student_list', 'school_club_student_spreadsheet')),
                ADD CONSTRAINT documents_single_owner_matches_kind
                    CHECK (
                        (kind = 'aviation_proof' AND membership_application_id IS NOT NULL AND payment_id IS NULL AND job_application_id IS NULL AND school_club_student_batch_id IS NULL)
                        OR (kind = 'payment_evidence' AND payment_id IS NOT NULL AND membership_application_id IS NULL AND job_application_id IS NULL AND school_club_student_batch_id IS NULL)
                        OR (kind = 'job_application_document' AND job_application_id IS NOT NULL AND membership_application_id IS NULL AND payment_id IS NULL AND school_club_student_batch_id IS NULL)
                        OR (kind IN ('school_club_student_list', 'school_club_student_spreadsheet') AND school_club_student_batch_id IS NOT NULL AND membership_application_id IS NULL AND payment_id IS NULL AND job_application_id IS NULL)
                    )
        ");
    }

    /**
     * Reversible only while no batch is awaiting its signed list and no Excel
     * document exists (the restored CHECKs would reject them).
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CHECK documents_kind_allowed, DROP CHECK documents_single_owner_matches_kind');
        DB::statement("
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
        ");

        DB::statement('ALTER TABLE school_club_student_batches DROP CHECK school_club_student_batches_status_allowed');
        DB::statement("ALTER TABLE school_club_student_batches ADD CONSTRAINT school_club_student_batches_status_allowed
            CHECK (status IN ('submitted', 'more_details_required', 'approved', 'rejected'))");

        Schema::table('school_club_student_entries', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'surname', 'date_of_birth']);
        });
    }
};
