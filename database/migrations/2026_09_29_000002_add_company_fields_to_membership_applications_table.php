<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corporate-only application fields (approved Architecture/Database
     * Design change). All nullable — every other category leaves these
     * NULL, the same pattern as the existing Student-only
     * (`study_start_date`/`expected_completion_date`) and Veteran-only
     * (`years_experience`/`previous_employers`) columns. "Which fields are
     * required for which category" stays a Form Request rule, not a schema
     * one (docs/database/04 §6).
     *
     * The representative's own name/email/phone/address/position reuse the
     * existing generic `full_name`/`email`/`mobile`/`address`/
     * `aviation_role`/`aviation_organisation` columns (relabelled for
     * Corporate) — exactly like every other category already reinterprets
     * `aviation_role`/`aviation_organisation`. Only the company's own
     * details, which have no existing column to reuse, are added here.
     */
    public function up(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->string('company_name', 160)->nullable()->after('full_name');
            $table->string('company_email', 255)->nullable()->after('email');
            $table->string('company_phone', 40)->nullable()->after('mobile');
            $table->string('company_website', 255)->nullable()->after('address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('membership_applications', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_email', 'company_phone', 'company_website']);
        });
    }
};
