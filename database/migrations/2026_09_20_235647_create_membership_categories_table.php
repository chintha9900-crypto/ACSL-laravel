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
     * Exactly three categories (S, P, V) — docs/database/04_MEMBERSHIP_SCHEMA.md §3.
     * Rows are data, not created here.
     */
    public function up(): void
    {
        Schema::create('membership_categories', function (Blueprint $table) {
            $table->id();
            $table->char('code', 1)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE membership_categories ADD CONSTRAINT membership_categories_code_allowed CHECK (code IN ('S', 'P', 'V'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('membership_categories');
    }
};
