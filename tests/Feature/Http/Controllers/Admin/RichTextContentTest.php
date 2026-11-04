<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\CsrProject;
use App\Models\EventListing;
use App\Models\NewsItem;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * The Blog's rich-text editor on the News, Events and CSR forms: toolbar on
 * create and edit, formatting kept through save, edit and the public page,
 * unsafe HTML removed, and older plain-text records still readable.
 */
class RichTextContentTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private const FORMATTED = '<h2>Heading two</h2><h3>Heading three</h3><p style="text-align: center;">Centred <strong>bold</strong> <em>italic</em> <u>underlined</u></p>'
        .'<ul><li>Bullet</li></ul><ol><li>Number</li></ol><blockquote>A quote</blockquote><p><a href="https://example.com/more">A link</a></p>';

    private const TOOLBAR = ['H2', 'H3', '¶', 'B', 'I', 'U', '• List', '1. List', '“ ”', 'Link', 'Unlink', 'Left', 'Centre', 'Right', 'Justify'];

    /**
     * @return array<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    public static function sections(): array
    {
        return [
            'news' => [NewsItem::class, 'news', 'news.show'],
            'events' => [EventListing::class, 'events', 'events.show'],
            'csr' => [CsrProject::class, 'csr', 'csr.show'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'A Formatted Record',
            'slug' => 'a-formatted-record',
            'content' => self::FORMATTED,
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            ...$overrides,
        ];
    }

    /**
     * @return list<string>
     */
    private function toolbarLabels(string $html): array
    {
        preg_match_all('#<button type="button" data-rte-command="[^"]*"[^>]*>([^<]*)</button>#u', $html, $buttons);

        return array_map(fn (string $label): string => html_entity_decode($label), $buttons[1]);
    }

    #[DataProvider('sections')]
    public function test_the_create_and_edit_forms_have_the_full_toolbar(string $model, string $prefix): void
    {
        $record = $model::factory()->create(['content' => self::FORMATTED]);
        $edit = $this->actingAs($this->admin())->get(route("admin.{$prefix}.edit", $record))->assertOk()->getContent();
        $create = $this->get(route("admin.{$prefix}.create"))->assertOk()->getContent();

        foreach ([$create, $edit] as $html) {
            $this->assertSame(self::TOOLBAR, $this->toolbarLabels($html));
            $this->assertSame(1, substr_count($html, 'data-rte-editor'.' hidden contenteditable="true"'));
            $this->assertSame(1, preg_match_all('#<textarea[^>]*name="content"#', $html));
        }

        // Editing reloads the saved formatting into the editor's textarea.
        $this->assertStringContainsString(e('<h2>Heading two</h2>'), $edit);
    }

    #[DataProvider('sections')]
    public function test_the_blog_and_section_toolbars_match(string $model, string $prefix): void
    {
        $this->actingAs($this->admin());
        $blog = $this->get(route('admin.blog.create'))->assertOk()->getContent();

        $this->assertSame($this->toolbarLabels($blog), $this->toolbarLabels($this->get(route("admin.{$prefix}.create"))->getContent()));
    }

    #[DataProvider('sections')]
    public function test_formatting_survives_saving_and_shows_on_the_public_page(string $model, string $prefix, string $publicRoute): void
    {
        $this->actingAs($this->admin())->post(route("admin.{$prefix}.store"), $this->payload())->assertSessionHasNoErrors();
        $record = $model::query()->sole();
        $this->assertSame(self::FORMATTED, $record->content);

        $updated = self::FORMATTED.'<p style="text-align: right;">Added</p>';
        $this->patch(route("admin.{$prefix}.update", $record), $this->payload(['content' => $updated]))->assertSessionHasNoErrors();
        $this->assertSame($updated, $record->fresh()->content);

        $record->forceFill(['status' => 'published', 'published_at' => now()->subDay()])->save();
        $html = $this->get(route($publicRoute, $record->slug))->assertOk()->getContent();
        $this->assertStringContainsString($updated, $html);
    }

    #[DataProvider('sections')]
    public function test_unsafe_html_is_removed_on_save_and_on_display(string $model, string $prefix, string $publicRoute): void
    {
        $unsafe = '<p onclick="alert(1)" style="color: red; position: fixed">Safe</p><script>alert(2)</script>'
            .'<a href="javascript:alert(3)">Bad</a><img src="x" onerror="alert(4)"><iframe src="https://evil.test"></iframe>';

        $this->actingAs($this->admin())->post(route("admin.{$prefix}.store"), $this->payload(['content' => $unsafe]))->assertSessionHasNoErrors();

        $record = $model::query()->sole();
        $this->assertSame('<p>Safe</p><a>Bad</a>', $record->content);

        // Even a row that bypassed the save action is cleaned when displayed.
        $record->forceFill(['content' => $unsafe, 'status' => 'published', 'published_at' => now()->subDay()])->save();
        $html = $this->get(route($publicRoute, $record->slug))->assertOk()->getContent();
        foreach (['onclick=', 'alert(2)', 'javascript:alert', 'onerror=', 'evil.test', 'position: fixed'] as $dangerous) {
            $this->assertStringNotContainsString($dangerous, $html);
        }
    }

    #[DataProvider('sections')]
    public function test_older_plain_text_records_stay_readable_on_the_public_page_and_in_the_editor(string $model, string $prefix, string $publicRoute): void
    {
        $record = $model::factory()->published()->create(['content' => "First line\nSecond line\n\nTom & Jerry say a < b <not-a-tag>"]);
        $expected = "<p>First line<br>\nSecond line</p><p>Tom &amp; Jerry say a &lt; b &lt;not-a-tag&gt;</p>";

        $this->assertStringContainsString($expected, $this->get(route($publicRoute, $record->slug))->assertOk()->getContent());

        $edit = $this->actingAs($this->admin())->get(route("admin.{$prefix}.edit", $record))->assertOk()->getContent();
        $this->assertStringContainsString(e($expected), $edit);
        $this->get(route("admin.{$prefix}.show", $record))->assertOk()->assertSee('First line');
    }

    public function test_the_full_csr_listing_shows_the_formatting_and_the_preview_stays_plain(): void
    {
        $project = CsrProject::factory()->published()->create(['content' => '<h2>Our work</h2><ul><li>Tom &amp; Jerry</li><li>Second</li></ul>']);

        $this->get(route('csr.index'))->assertOk()->assertSee('<h2>Our work</h2>', false);
        $this->assertSame("Our work\nTom & Jerry\nSecond", $project->previewLines());
    }
}
