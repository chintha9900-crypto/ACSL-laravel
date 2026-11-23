<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aviation job vacancies (Jobs module, Stage 1). Named `job_listings`
 * because `jobs` is Laravel's queue table.
 *
 * The allowed category, employment type, location type and status values
 * mirror the JobListing model's constants and are enforced by CHECK
 * constraints. `application_deadline` is a calendar date in Asia/Colombo:
 * a job stays visible throughout that date. `application_url` is nullable
 * so drafts can be saved without it; publishing will require it (Stage 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->string('company_name', 255);
            $table->string('company_logo', 255)->nullable();
            $table->string('category', 50)->charset('ascii')->collation('ascii_bin');
            $table->string('employment_type', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('location_type', 20)->charset('ascii')->collation('ascii_bin');
            $table->string('location', 255);
            $table->string('country', 100);
            $table->string('short_description', 500);
            $table->mediumText('description');
            $table->mediumText('responsibilities')->nullable();
            $table->mediumText('qualifications')->nullable();
            $table->mediumText('experience')->nullable();
            $table->mediumText('additional_requirements')->nullable();
            $table->string('salary', 255)->nullable();
            $table->date('date_posted')->nullable();
            $table->date('application_deadline');
            $table->string('application_url', 2048)->nullable();
            $table->string('status', 20)->charset('ascii')->collation('ascii_bin')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'application_deadline']);
            $table->index('location_type');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE job_listings
                ADD CONSTRAINT job_listings_category_allowed CHECK (category IN (
                    'pilots', 'aircraft_engineering_maintenance', 'cabin_crew', 'flight_operations',
                    'airport_operations', 'ground_handling', 'air_traffic_management',
                    'aviation_management_administration', 'aviation_training_education', 'other'
                )),
                ADD CONSTRAINT job_listings_employment_type_allowed
                    CHECK (employment_type IN ('full_time', 'part_time', 'contract', 'temporary', 'internship')),
                ADD CONSTRAINT job_listings_location_type_allowed CHECK (location_type IN ('local', 'overseas')),
                ADD CONSTRAINT job_listings_status_allowed CHECK (status IN ('draft', 'published', 'expired'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('job_listings');
    }
};
