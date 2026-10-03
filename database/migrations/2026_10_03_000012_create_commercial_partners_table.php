<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Database foundation only (Phase 1.4A) — approved external businesses/
     * organisations with a commercial partnership with Aviation Club
     * International, publicly listed on the website. A flat, uniform list
     * (no taxonomy, no workflow): `is_active` alone gates visibility, same
     * convention as `product_categories.is_active` — there is no
     * `status`/`published_at` pair here, unlike `news_items`/
     * `event_listings`/`csr_projects`, because nothing in the approved
     * definition describes a draft/publish lifecycle for a partner entry.
     * `display_order` lets an admin order the list manually, same column
     * name/type/default as `product_images.display_order`.
     */
    public function up(): void
    {
        Schema::create('commercial_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('logo_path', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_partners');
    }
};
