<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\CsrProject;
use DOMDocument;
use DOMXPath;
use Tests\MysqlTestCase;

class CsrControllerTest extends MysqlTestCase
{
    /**
     * Scoped to identifier-carrying attributes (href/action/value/data-*),
     * not every attribute indiscriminately — the header's decorative SVGs use
     * small numeric geometry attributes that can coincidentally equal a test
     * id without exposing anything.
     */
    private function assertIdNotExposed(int $id, string $html): void
    {
        $needle = (string) $id;

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//@*[name()="href" or name()="action" or name()="value" or starts-with(name(), "data-")]') as $attribute) {
            $this->assertNotSame($needle, trim((string) $attribute->nodeValue), "The [{$attribute->nodeName}] attribute must not expose the raw id {$id}.");
        }
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function contentFromLines(array $lines): string
    {
        return collect($lines)->map(fn (string $line) => '<p>'.$line.'</p>')->implode('');
    }

    // --- navigation -------------------------------------------------------

    public function test_the_main_navigation_links_to_the_csr_page(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('CSR');
        $response->assertSee('href="'.route('csr.index').'"', false);
    }

    // --- listing: 5 or fewer projects (no preview truncation) -------------

    public function test_the_listing_shows_only_published_projects(): void
    {
        CsrProject::factory()->published()->create(['title' => 'Published Project']);
        CsrProject::factory()->create(['title' => 'Draft Project']);

        $response = $this->get(route('csr.index'))->assertOk();

        $response->assertSee('Published Project');
        $response->assertDontSee('Draft Project');
    }

    public function test_it_shows_an_empty_state_when_there_are_no_published_projects(): void
    {
        $this->get(route('csr.index'))->assertOk()->assertSee('No CSR projects to show yet.');
    }

    public function test_with_five_or_fewer_projects_the_full_content_shows_and_there_is_no_view_more(): void
    {
        CsrProject::factory()->published()->count(4)->create();
        $project = CsrProject::factory()->published()->create([
            'title' => 'Beach Cleanup',
            'content' => '<p>We cleaned the beach together with volunteers from the local community.</p>',
        ]);

        $response = $this->get(route('csr.index'))->assertOk();

        $response->assertSee('Beach Cleanup');
        $response->assertSee('We cleaned the beach together with volunteers from the local community.', false);
        $response->assertDontSee('View More');
    }

    // --- listing: more than 5 projects (preview + View More) --------------

    public function test_with_more_than_five_projects_the_listing_shows_only_the_first_ten_lines_and_a_view_more_button(): void
    {
        CsrProject::factory()->published()->count(5)->create();
        $lines = array_map(fn (int $n) => "Project line {$n}.", range(1, 15));
        $project = CsrProject::factory()->published()->create([
            'title' => 'Tree Planting Drive',
            'content' => $this->contentFromLines($lines),
        ]);

        $response = $this->get(route('csr.index'))->assertOk();
        $html = $response->getContent();

        $response->assertSee('Tree Planting Drive');
        foreach (array_slice($lines, 0, 10) as $line) {
            $response->assertSee($line);
        }
        $this->assertStringNotContainsString('Project line 11.', $html);
        $response->assertSee('View More');
        $response->assertSee('href="'.route('csr.show', $project->slug).'"', false);
    }

    public function test_the_listing_never_exposes_a_raw_project_id(): void
    {
        $project = CsrProject::factory()->published()->create();
        CsrProject::factory()->published()->count(5)->create();

        $html = $this->get(route('csr.index'))->assertOk()->getContent();

        $this->assertIdNotExposed($project->id, $html);
    }

    // --- individual project page -------------------------------------------

    public function test_a_published_project_is_visible_by_slug_with_its_full_content(): void
    {
        $lines = array_map(fn (int $n) => "Project line {$n}.", range(1, 15));
        $project = CsrProject::factory()->published()->create([
            'title' => 'Tree Planting Drive',
            'slug' => 'tree-planting-drive',
            'content' => $this->contentFromLines($lines),
        ]);

        $response = $this->get(route('csr.show', $project->slug))->assertOk();

        $response->assertSee('Tree Planting Drive');
        foreach ($lines as $line) {
            $response->assertSee($line);
        }
    }

    public function test_the_individual_project_page_has_a_back_to_csr_button(): void
    {
        $project = CsrProject::factory()->published()->create();

        $response = $this->get(route('csr.show', $project->slug))->assertOk();

        $response->assertSee('Back to CSR');
        $response->assertSee('href="'.route('csr.index').'"', false);
    }

    public function test_a_draft_project_404s_like_a_nonexistent_slug(): void
    {
        CsrProject::factory()->create(['slug' => 'draft-project']);

        $draftResponse = $this->get('/csr/draft-project');
        $missingResponse = $this->get('/csr/does-not-exist');

        $draftResponse->assertNotFound();
        $missingResponse->assertNotFound();
    }

    public function test_a_not_yet_due_project_404s(): void
    {
        CsrProject::factory()->create([
            'slug' => 'future-project',
            'status' => CsrProject::STATUS_PUBLISHED,
            'published_at' => now()->addWeek(),
        ]);

        $this->get('/csr/future-project')->assertNotFound();
    }

    public function test_the_project_page_never_exposes_a_raw_project_id(): void
    {
        $project = CsrProject::factory()->published()->create();

        $html = $this->get(route('csr.show', $project->slug))->assertOk()->getContent();

        $this->assertIdNotExposed($project->id, $html);
    }
}
