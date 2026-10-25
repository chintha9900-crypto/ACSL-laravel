<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blog: one featured post (enforced by a unique generated key — at most one
 * row can have it), tags, and member comments moderated by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
            $table->unsignedTinyInteger('featured_key')->nullable()->virtualAs('IF(is_featured, 1, NULL)')->after('is_featured');
            $table->unique('featured_key');
        });

        Schema::create('blog_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->timestamps();
        });

        Schema::create('blog_post_tag', function (Blueprint $table) {
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('blog_tag_id')->constrained('blog_tags')->cascadeOnDelete();
            $table->primary(['blog_post_id', 'blog_tag_id']);
            $table->index('blog_tag_id');
        });

        Schema::create('blog_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');
            $table->text('body');
            $table->string('status', 20)->default('pending');
            $table->foreignId('moderated_by_user_id')->nullable()->constrained('users');
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['blog_post_id', 'status', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE blog_comments ADD CONSTRAINT blog_comments_status_allowed CHECK (status IN ('pending', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE blog_comments ADD CONSTRAINT blog_comments_moderation_consistent CHECK ((status = \'pending\') = (moderated_at IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_comments');
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_tags');

        Schema::table('blog_posts', function (Blueprint $table) {
            $table->dropUnique(['featured_key']);
            $table->dropColumn(['featured_key', 'is_featured']);
        });
    }
};
