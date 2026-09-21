<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the foreign key that the T2 `documents` migration deferred until
     * `payments` existed (docs/database/07_DOCUMENT_SCHEMA.md §3). Default
     * NO ACTION, per docs/database/18 A-1; the column already carries an index.
     * `membership_details_request_id` and `job_application_id` stay deferred.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('payments');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });
    }
};
