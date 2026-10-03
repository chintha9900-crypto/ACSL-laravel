<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presentational child of `products` — CASCADE on delete (docs/database/12
     * §1.4). `image_path` lives on the `public` disk, the same convention as
     * `news_items.image_path`/`blog_posts.featured_image_path`. The first
     * image by `display_order` is the primary image; no separate flag.
     */
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('image_path', 255);
            $table->string('alt_text', 255)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
