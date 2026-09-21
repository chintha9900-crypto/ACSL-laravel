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
     * The stable, lifelong member record — docs/database/04_MEMBERSHIP_SCHEMA.md §8.
     * Created once, at first activation. Carries the membership number
     * (CYYRRSSSS) and no validity, fee or payment state (those are terms).
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_application_id')->unique()->constrained('membership_applications');
            $table->foreignId('user_id')->nullable()->unique()->constrained('users');
            $table->foreignId('membership_category_id')->constrained('membership_categories');
            $table->char('membership_number', 9)->charset('ascii')->collation('ascii_bin')->unique();
            $table->unsignedSmallInteger('number_year');
            $table->unsignedSmallInteger('number_sequence');
            $table->timestamp('activated_at');
            $table->date('activated_on');
            $table->string('verification_token', 64)->charset('ascii')->collation('ascii_bin')->nullable()->unique();
            $table->timestamps();

            $table->unique(
                ['membership_category_id', 'number_year', 'number_sequence'],
                'memberships_category_year_sequence_unique'
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE memberships
                ADD CONSTRAINT memberships_number_format
                    CHECK (membership_number REGEXP '^[SPV][0-9]{8}$'),
                ADD CONSTRAINT memberships_number_sequence_range
                    CHECK (number_sequence BETWEEN 1 AND 9999),
                ADD CONSTRAINT memberships_number_matches_components
                    CHECK (
                        SUBSTRING(membership_number, 2, 2) = LPAD(number_year MOD 100, 2, '0')
                        AND SUBSTRING(membership_number, 6, 4) = LPAD(number_sequence, 4, '0')
                    )
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships');
    }
};
