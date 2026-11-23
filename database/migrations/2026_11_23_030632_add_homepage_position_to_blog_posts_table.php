<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Homepage "Featured insights & events" article slots: NULL = not on the
 * homepage, 1 = middle column, 2 = right column. Unique, so one article per
 * slot and never the same slot twice (MySQL allows many NULLs in a unique
 * index). Independent of the existing single `is_featured` blog-top post.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->unsignedTinyInteger('homepage_position')->nullable()->after('is_featured');
            $table->unique('homepage_position');
        });

        DB::statement('ALTER TABLE blog_posts ADD CONSTRAINT blog_posts_homepage_position_allowed CHECK (homepage_position IS NULL OR homepage_position IN (1, 2))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE blog_posts DROP CHECK blog_posts_homepage_position_allowed');

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropUnique(['homepage_position']);
            $table->dropColumn('homepage_position');
        });
    }
};
