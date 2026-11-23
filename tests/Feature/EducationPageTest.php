<?php

namespace Tests\Feature;

use App\Models\EducationArticle;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\MysqlTestCase;

/**
 * The public Education page (database-driven) and each article's page. The
 * migration inserts the nine original cards, so they are present here.
 */
class EducationPageTest extends MysqlTestCase
{
    private const LEFT_TITLES = [
        'Flight Training',
        'Aircraft Engineering and Maintenance',
        'Cabin Crew Training',
        'Aviation Operations and Airport Management',
        'Other Aviation Learning Opportunities',
    ];

    private const RIGHT_TITLES = [
        'Education resources',
        'Considering an aviation career?',
        'Training institutions',
        'Join the community',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * @return list<string>
     */
    private function cardTitles(DOMXPath $xpath, string $column): array
    {
        $titles = [];
        foreach ($xpath->query('//*[@data-education-card="'.$column.'"]/*[self::h2 or self::h3]') as $heading) {
            $titles[] = trim($heading->textContent);
        }

        return $titles;
    }

    private function inlineImageUrl(): string
    {
        $file = strtolower((string) Str::ulid()).'.png';
        Storage::disk('public')->put(EducationArticle::INLINE_IMAGE_DIRECTORY.'/'.$file, 'png');

        return EducationArticle::inlineImageUrlPrefix().$file;
    }

    // --- the nine migrated cards -------------------------------------------------

    public function test_the_nine_original_cards_are_shown_word_for_word_in_their_columns_and_order(): void
    {
        $response = $this->get(route('education'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(self::LEFT_TITLES, $this->cardTitles($xpath, 'left'));
        $this->assertSame(self::RIGHT_TITLES, $this->cardTitles($xpath, 'right'));

        $response->assertSeeInOrder([
            'Learning to fly, from first lessons through to the training required for a professional flying career. Flight training combines ground study with supervised time in the aircraft.',
            'Keeping aircraft safe and airworthy. Engineering and maintenance study covers aircraft systems, inspection and repair, with a strong focus on safety and accuracy.',
            'Preparing for a role in the cabin, where safety, emergency procedures and passenger care come together on every flight.',
            'The work that keeps flights moving on the ground and behind the scenes, including flight operations, airport operations and aviation management.',
            'Aviation also includes air traffic control, aerospace and aeronautical engineering, aviation safety, cargo and many more specialist areas, each with its own path of study.',
            'Resources coming soon.',
            'Explore the different areas of aviation, talk to people already working in the industry, and choose a pathway that matches your interests. Students studying an aviation-related subject can join the club as Aviation Student members.',
            'Information about aviation training institutions is coming soon. Schools, colleges and universities can support aviation clubs through School &amp; University Club Membership.',
            'Become a member to connect with people who work, study or take part in aviation.',
        ], false);
    }

    public function test_the_original_header_intro_and_layout_are_kept(): void
    {
        $response = $this->get(route('education'))->assertOk();
        $response->assertSeeInOrder([
            'Aviation depends on people who keep learning.',
            'Aviation education and training pathways',
            'There are many ways into aviation. These are some of the main areas of study and training.',
        ]);

        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//div[@class="site-container grid gap-10 py-16 md:grid-cols-[2fr_1fr] md:py-20 lg:grid-cols-[7fr_3fr] lg:gap-12"]')->length);
        $this->assertSame(5, $xpath->query('//div[@data-education-card="left"][@class="ui-card p-6 md:p-8"]')->length);
        $this->assertSame(4, $xpath->query('//aside/div[@data-education-card="right"][@class="ui-card p-6"]')->length);
    }

    public function test_the_sidebar_buttons_keep_their_original_behaviour_and_style(): void
    {
        $xpath = $this->xpath($this->get(route('education'))->getContent());

        $career = $xpath->query('//*[@data-education-card="right"][.//a[contains(., "Considering")]]//a[normalize-space(.)="View membership types"]')->item(0);
        $this->assertSame('/membership/benefits', $career->getAttribute('href'));
        $this->assertSame('mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline', $career->getAttribute('class'));

        $join = $xpath->query('//*[@data-education-card="right"][.//a[contains(., "Join the community")]]//div/a');
        $this->assertSame(['Become a Member', 'Contact Us'], [trim($join->item(0)->textContent), trim($join->item(1)->textContent)]);
        $this->assertSame(['/membership/apply', '/contact'], [$join->item(0)->getAttribute('href'), $join->item(1)->getAttribute('href')]);
        $this->assertSame('btn btn-sm btn-brand', $join->item(0)->getAttribute('class'));
        $this->assertSame('btn btn-sm btn-ghost border border-border', $join->item(1)->getAttribute('class'));

        $this->get('/membership/benefits')->assertOk();
        $this->get('/membership/apply')->assertOk();
        $this->get('/contact')->assertOk();
    }

    // --- dynamic behaviour ---------------------------------------------------------

    public function test_only_published_articles_are_shown(): void
    {
        EducationArticle::factory()->create(['title' => 'Unpublished draft topic']);
        EducationArticle::factory()->published()->create(['title' => 'Freshly published topic']);

        $this->get(route('education'))->assertSee('Freshly published topic')->assertDontSee('Unpublished draft topic');
    }

    public function test_column_and_display_order_are_respected(): void
    {
        EducationArticle::query()->delete();
        EducationArticle::factory()->published()->create(['title' => 'Left B', 'sort_order' => 2]);
        EducationArticle::factory()->published()->create(['title' => 'Left A', 'sort_order' => 1]);
        EducationArticle::factory()->published()->right()->create(['title' => 'Right Z', 'sort_order' => 9]);
        EducationArticle::factory()->published()->right()->create(['title' => 'Right Y', 'sort_order' => 3]);

        $xpath = $this->xpath($this->get(route('education'))->getContent());

        $this->assertSame(['Left A', 'Left B'], $this->cardTitles($xpath, 'left'));
        $this->assertSame(['Right Y', 'Right Z'], $this->cardTitles($xpath, 'right'));
    }

    public function test_each_card_opens_its_complete_article(): void
    {
        $image = $this->inlineImageUrl();
        $article = EducationArticle::factory()->published()->create([
            'title' => 'Becoming a Pilot',
            'short_description' => 'A short summary.',
            'content' => '<h2>Getting started</h2><p>First <strong>steps</strong> and <a href="https://example.com/guide">a guide</a>.</p><ul><li>Medical</li><li>Ground school</li></ul><p><img src="'.$image.'" alt="Training aircraft"></p><p>The rest of the article.</p>',
        ]);

        $href = $this->xpath($this->get(route('education'))->getContent())
            ->query('//*[@data-education-card="left"][.//a[normalize-space(.)="Becoming a Pilot"]]//h3/a')->item(0)->getAttribute('href');
        $this->assertSame(route('education.show', $article), $href);

        $html = $this->get($href)->assertOk()
            ->assertSeeInOrder(['Becoming a Pilot', 'A short summary.', 'Back to Education', 'Getting started', 'steps', 'a guide', 'Medical', 'Ground school', 'The rest of the article.'])
            ->assertSee('href="'.route('education').'"', false)
            ->getContent();

        $this->assertStringContainsString('<h2>Getting started</h2>', $html);
        $this->assertStringContainsString('<ul><li>Medical</li><li>Ground school</li></ul>', $html);
        $this->assertStringContainsString('<img src="'.$image.'" alt="Training aircraft">', $html);
    }

    public function test_the_featured_image_and_its_alt_text_are_shown(): void
    {
        Storage::disk('public')->put('education/featured/x.png', 'png');
        $article = EducationArticle::factory()->published()->create(['featured_image_path' => 'education/featured/x.png', 'featured_image_alt' => 'Students in a hangar']);

        $url = Storage::disk('public')->url('education/featured/x.png');
        $this->get(route('education'))->assertSee('src="'.$url.'" alt="Students in a hangar"', false);
        $this->get(route('education.show', $article))->assertSee('src="'.$url.'" alt="Students in a hangar"', false);
    }

    public function test_unsafe_html_and_foreign_images_are_never_rendered(): void
    {
        $article = EducationArticle::factory()->published()->create(['title' => '<b>Title</b>']);
        $article->forceFill(['content' => '<p>Safe</p><script>alert("x")</script><img src="https://evil.example/x.png" alt="x"><img src="data:image/png;base64,AAAA"><p onclick="steal()">Text</p>'])->save();

        $html = $this->get(route('education.show', $article))->assertOk()->getContent();

        $this->assertStringContainsString('<p>Safe</p>', $html);
        $this->assertStringNotContainsString('alert("x")', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('data:image', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('&lt;b&gt;Title&lt;/b&gt;', $html);
    }

    public function test_drafts_and_unknown_slugs_return_404(): void
    {
        $draft = EducationArticle::factory()->create();

        $this->get(route('education.show', $draft))->assertNotFound();
        $this->get('/education/no-such-article')->assertNotFound();
        $this->get('/education/'.$draft->id)->assertNotFound();
    }

    public function test_the_secondary_strip_links_to_the_education_page(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('href="'.route('education').'"', false);
    }
}
