<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the foreign key that the T2 `documents` migration deferred until its
     * parent existed (docs/database/07_DOCUMENT_SCHEMA.md §3). Default NO ACTION;
     * the column already carries an index.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('membership_details_request_id')->references('id')->on('membership_details_requests');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['membership_details_request_id']);
        });
    }
};
