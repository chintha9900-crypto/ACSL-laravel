<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * docs/database/10_CONTENT_SCHEMA.md §7. Named `event_listings` (not
     * `events`) to match the `EventListing` model and avoid a class name
     * collision. `ends_at`, capacity and registration are not modelled — no
     * confirmed requirement (architecture `10` §7). No status CHECK
     * constraint, same reasoning as `news_items`.
     */
    public function up(): void
    {
        Schema::create('event_listings', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->text('excerpt')->nullable();
            $table->mediumText('content');
            $table->string('image_path', 255)->nullable();
            $table->timestamp('starts_at');
            $table->string('location', 255)->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_listings');
    }
};
