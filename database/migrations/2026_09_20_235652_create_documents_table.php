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
     * Private-file metadata with an exclusive owner arc —
     * docs/database/07_DOCUMENT_SCHEMA.md §3.
     *
     * `membership_details_request_id`, `payment_id` and `job_application_id`
     * reference tables that are outside this tranche. The columns and CHECKs
     * are created as documented; their foreign keys are added by the migration
     * that creates each parent table (default NO ACTION, per docs/database/18 A-1).
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('kind', 40)->charset('ascii')->collation('ascii_bin');
            $table->foreignId('membership_application_id')->nullable()->constrained('membership_applications');
            $table->unsignedBigInteger('membership_details_request_id')->nullable()->index();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('job_application_id')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users');
            $table->string('disk', 30)->charset('ascii')->default('private');
            $table->string('storage_path', 500)->charset('ascii')->collation('ascii_bin');
            $table->string('original_filename');
            $table->string('mime_type', 127);
            $table->unsignedInteger('size_bytes');
            $table->char('checksum_sha256', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('visibility', 20)->charset('ascii')->collation('ascii_bin')->default('owner_and_admin');
            $table->timestamp('purged_at')->nullable();
            $table->foreignId('purged_by_user_id')->nullable()->constrained('users');
            $table->string('purge_reason')->nullable();
            $table->timestamps();

            $table->unique(['disk', 'storage_path']);
            $table->index(['membership_application_id', 'kind']);
            $table->index('payment_id');
            $table->index('job_application_id');
            $table->index('checksum_sha256');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE documents
                ADD CONSTRAINT documents_kind_allowed
                    CHECK (kind IN ('aviation_proof', 'payment_evidence', 'job_application_document')),
                ADD CONSTRAINT documents_visibility_allowed
                    CHECK (visibility IN ('owner_and_admin', 'admin_only')),
                ADD CONSTRAINT documents_single_owner_matches_kind
                    CHECK (
                        (kind = 'aviation_proof' AND membership_application_id IS NOT NULL AND payment_id IS NULL AND job_application_id IS NULL)
                        OR (kind = 'payment_evidence' AND payment_id IS NOT NULL AND membership_application_id IS NULL AND job_application_id IS NULL)
                        OR (kind = 'job_application_document' AND job_application_id IS NOT NULL AND membership_application_id IS NULL AND payment_id IS NULL)
                    ),
                ADD CONSTRAINT documents_details_request_is_aviation_proof
                    CHECK (membership_details_request_id IS NULL OR kind = 'aviation_proof'),
                ADD CONSTRAINT documents_disk_not_public CHECK (disk <> 'public'),
                ADD CONSTRAINT documents_size_positive CHECK (size_bytes > 0),
                ADD CONSTRAINT documents_purge_pair_consistent
                    CHECK ((purged_at IS NULL) = (purged_by_user_id IS NULL))
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
