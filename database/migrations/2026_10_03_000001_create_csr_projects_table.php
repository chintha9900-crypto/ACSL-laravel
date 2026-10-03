<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CSR projects, kept separate from `blog_posts`/`news_items` (its own
     * public section, not merged into either) but following the same
     * draft/published + `published_at` shape as `news_items` — no status
     * CHECK constraint, same as `news_items`/`event_listings`
     * (14_INDEX_AND_CONSTRAINT_STRATEGY.md's enum-only, not CHECK-guarded,
     * convention). No `excerpt` column: the listing's preview is computed
     * from the first lines of `content` itself (see `CsrProject::previewLines()`),
     * not a separately authored summary.
     */
    public function up(): void
    {
        Schema::create('csr_projects', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->mediumText('content');
            $table->string('image_path', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csr_projects');
    }
};
