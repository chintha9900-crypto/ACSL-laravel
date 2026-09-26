<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/database/10_CONTENT_SCHEMA.md §6. Kept separate from `blog_posts`
     * (architecture `10` §3 — News vs. Blog are not silently merged). No
     * status CHECK constraint: `14_INDEX_AND_CONSTRAINT_STRATEGY.md` lists
     * `news_items.status` among the enum-only, deliberately not
     * CHECK-guarded columns.
     */
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->text('excerpt')->nullable();
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
        Schema::dropIfExists('news_items');
    }
};
