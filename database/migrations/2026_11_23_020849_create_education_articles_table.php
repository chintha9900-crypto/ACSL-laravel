<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Education articles: the topic cards on the public Education page and the
 * full article behind each one. `display_column` places a card in the left
 * (main) or right (sidebar) column; `sort_order` orders it within that
 * column. The two optional button pairs carry the sidebar cards' existing
 * links/buttons.
 *
 * The nine cards that were hard-coded in resources/views/education.blade.php
 * are inserted here, word for word and in their original columns and order,
 * as published articles — so the public page is unchanged after migrating
 * and nothing is lost. Each card's sentence is also its initial article text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->string('slug', 255)->unique();
            $table->string('short_description', 500);
            $table->mediumText('content');
            $table->string('featured_image_path', 255)->nullable();
            $table->string('featured_image_alt', 255)->nullable();
            $table->string('display_column', 10)->charset('ascii')->collation('ascii_bin');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('button_label', 100)->nullable();
            $table->string('button_url', 2048)->nullable();
            $table->string('secondary_button_label', 100)->nullable();
            $table->string('secondary_button_url', 2048)->nullable();
            $table->string('status', 20)->charset('ascii')->collation('ascii_bin')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'display_column', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE education_articles
                ADD CONSTRAINT education_articles_column_allowed CHECK (display_column IN ('left', 'right')),
                ADD CONSTRAINT education_articles_status_allowed CHECK (status IN ('draft', 'published')),
                ADD CONSTRAINT education_articles_button_pair
                    CHECK ((button_label IS NULL) = (button_url IS NULL)),
                ADD CONSTRAINT education_articles_secondary_button_pair
                    CHECK ((secondary_button_label IS NULL) = (secondary_button_url IS NULL))
            SQL);

        $now = now();
        $card = fn (string $column, int $order, string $title, string $slug, string $text, array $buttons = []): array => [
            'title' => $title,
            'slug' => $slug,
            'short_description' => $text,
            'content' => '<p>'.htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8').'</p>',
            'display_column' => $column,
            'sort_order' => $order,
            'button_label' => $buttons[0] ?? null,
            'button_url' => $buttons[1] ?? null,
            'secondary_button_label' => $buttons[2] ?? null,
            'secondary_button_url' => $buttons[3] ?? null,
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('education_articles')->insert([
            $card('left', 1, 'Flight Training', 'flight-training', 'Learning to fly, from first lessons through to the training required for a professional flying career. Flight training combines ground study with supervised time in the aircraft.'),
            $card('left', 2, 'Aircraft Engineering and Maintenance', 'aircraft-engineering-and-maintenance', 'Keeping aircraft safe and airworthy. Engineering and maintenance study covers aircraft systems, inspection and repair, with a strong focus on safety and accuracy.'),
            $card('left', 3, 'Cabin Crew Training', 'cabin-crew-training', 'Preparing for a role in the cabin, where safety, emergency procedures and passenger care come together on every flight.'),
            $card('left', 4, 'Aviation Operations and Airport Management', 'aviation-operations-and-airport-management', 'The work that keeps flights moving on the ground and behind the scenes, including flight operations, airport operations and aviation management.'),
            $card('left', 5, 'Other Aviation Learning Opportunities', 'other-aviation-learning-opportunities', 'Aviation also includes air traffic control, aerospace and aeronautical engineering, aviation safety, cargo and many more specialist areas, each with its own path of study.'),
            $card('right', 1, 'Education resources', 'education-resources', 'Resources coming soon.'),
            $card('right', 2, 'Considering an aviation career?', 'considering-an-aviation-career', 'Explore the different areas of aviation, talk to people already working in the industry, and choose a pathway that matches your interests. Students studying an aviation-related subject can join the club as Aviation Student members.', ['View membership types', '/membership/benefits']),
            $card('right', 3, 'Training institutions', 'training-institutions', 'Information about aviation training institutions is coming soon. Schools, colleges and universities can support aviation clubs through School & University Club Membership.'),
            $card('right', 4, 'Join the community', 'join-the-community', 'Become a member to connect with people who work, study or take part in aviation.', ['Become a Member', '/membership/apply', 'Contact Us', '/contact']),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('education_articles');
    }
};
