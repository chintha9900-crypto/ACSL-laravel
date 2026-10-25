<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An invited editor has no membership, but finishes their account through
     * the same one-time setup token as a newly activated member. The token's
     * membership link therefore becomes optional; the foreign key is kept.
     */
    public function up(): void
    {
        Schema::table('account_setup_tokens', function (Blueprint $table) {
            $table->dropForeign(['membership_id']);
        });

        Schema::table('account_setup_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('membership_id')->nullable()->change();
            $table->foreign('membership_id')->references('id')->on('memberships');
        });
    }

    /**
     * Only reversible while no invitation token (membership_id NULL) exists.
     */
    public function down(): void
    {
        Schema::table('account_setup_tokens', function (Blueprint $table) {
            $table->dropForeign(['membership_id']);
        });

        Schema::table('account_setup_tokens', function (Blueprint $table) {
            $table->unsignedBigInteger('membership_id')->nullable(false)->change();
            $table->foreign('membership_id')->references('id')->on('memberships');
        });
    }
};
