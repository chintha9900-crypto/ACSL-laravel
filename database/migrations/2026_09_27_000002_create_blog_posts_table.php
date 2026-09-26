<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/database/10_CONTENT_SCHEMA.md §3. `reading_time` is dropped (a
     * derived accessor, not stored). `published_at` is stamped by an Action
     * on any transition into `published`, never here. SEO columns
     * (`meta_title`, `meta_description`, `og_image_path`) exist per the
     * approved schema but have no admin form field yet — only the fields
     * this task's admin UI actually asks for do.
     */
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('blog_category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->text('excerpt')->nullable();
            $table->mediumText('content');
            $table->string('featured_image_path', 255)->nullable();
            $table->string('featured_image_alt', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('og_image_path', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });

        DB::statement("ALTER TABLE blog_posts ADD CONSTRAINT blog_posts_status_allowed CHECK (status IN ('draft', 'published'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
