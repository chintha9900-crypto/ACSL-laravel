<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One admin "more details" request and the applicant's response —
     * docs/database/04_MEMBERSHIP_SCHEMA.md §7. `open_request_key` is a VIRTUAL
     * generated column whose UNIQUE index allows at most one unanswered request
     * per application. FKs use the default NO ACTION (docs/database/18 A-1).
     */
    public function up(): void
    {
        Schema::create('membership_details_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_application_id')->constrained('membership_applications');
            $table->foreignId('requested_by_user_id')->constrained('users');
            $table->text('request_message');
            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->text('response_message')->nullable();
            $table->unsignedBigInteger('open_request_key')->nullable()
                ->virtualAs('IF(responded_at IS NULL, membership_application_id, NULL)');
            $table->timestamps();

            $table->unique('open_request_key');
            $table->index(['membership_application_id', 'requested_at'], 'membership_details_requests_application_requested_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_details_requests');
    }
};
