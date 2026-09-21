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
     * One counter row per (category, year) — the lock target that makes the
     * SSSS part of the membership number transaction-safe
     * (docs/database/04_MEMBERSHIP_SCHEMA.md §10). Never MAX()+1.
     */
    public function up(): void
    {
        Schema::create('membership_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_category_id')->constrained('membership_categories');
            $table->unsignedSmallInteger('sequence_year');
            $table->unsignedSmallInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(
                ['membership_category_id', 'sequence_year'],
                'membership_number_sequences_category_year_unique'
            );
        });

        DB::statement('ALTER TABLE membership_number_sequences ADD CONSTRAINT membership_number_sequences_last_number_range CHECK (last_number BETWEEN 0 AND 9999)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_number_sequences');
    }
};
