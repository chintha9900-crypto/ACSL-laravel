<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\JobListing;
use DOMDocument;
use DOMXPath;
use Illuminate\Testing\TestResponse;
use Tests\MysqlTestCase;

class PublicBreadcrumbsTest extends MysqlTestCase
{
    /**
     * The breadcrumb items as rendered: label, and the link target (null for
     * plain text).
     *
     * @return list<array{label: string, href: string|null}>
     */
    private function breadcrumbs(TestResponse $response): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new DOMXPath($dom);

        $items = [];

        foreach ($xpath->query('//nav[@data-breadcrumbs]//li') as $item) {
            $link = $xpath->query('.//a', $item)->item(0);
            $label = $xpath->query('.//a | .//span[not(@aria-hidden)]', $item)->item(0);

            $items[] = [
                'label' => trim($label->textContent),
                'href' => $link?->getAttribute('href'),
            ];
        }

        return $items;
    }

    public function test_a_top_level_page_shows_home_and_the_page_as_plain_text(): void
    {
        $response = $this->get(route('contact'))->assertOk();

        $this->assertSame([
            ['label' => 'Home', 'href' => route('home')],
            ['label' => 'Contact', 'href' => null],
        ], $this->breadcrumbs($response));
        $response->assertSee('<span aria-current="page" class="break-words">Contact</span>', false);
    }

    public function test_membership_pages_show_membership_as_plain_text_because_it_has_no_page(): void
    {
        $response = $this->get(route('membership.benefits'))->assertOk();

        $this->assertSame([
            ['label' => 'Home', 'href' => route('home')],
            ['label' => 'Membership', 'href' => null],
            ['label' => 'Benefits', 'href' => null],
        ], $this->breadcrumbs($response));
    }

    public function test_a_job_page_links_to_the_board_and_ends_with_the_job_title(): void
    {
        $job = JobListing::factory()->published()->create(['title' => 'Flight Operations Officer']);

        $this->assertSame([
            ['label' => 'Home', 'href' => route('home')],
            ['label' => 'Jobs', 'href' => route('jobs')],
            ['label' => 'Flight Operations Officer', 'href' => null],
        ], $this->breadcrumbs($this->get(route('jobs.show', $job))->assertOk()));
    }

    public function test_a_blog_article_ends_with_its_title_not_its_slug(): void
    {
        $post = BlogPost::factory()->published()->create(['title' => 'Crosswind Landings', 'slug' => 'crosswind-landings-2026']);

        $this->assertSame([
            ['label' => 'Home', 'href' => route('home')],
            ['label' => 'Blog', 'href' => route('blog.index')],
            ['label' => 'Crosswind Landings', 'href' => null],
        ], $this->breadcrumbs($this->get(route('blog.show', $post))->assertOk()));
    }

    public function test_search_filters_and_pagination_add_no_levels(): void
    {
        $this->assertSame([
            ['label' => 'Home', 'href' => route('home')],
            ['label' => 'Jobs', 'href' => null],
        ], $this->breadcrumbs($this->get(route('jobs', ['q' => 'pilot', 'location' => 'local', 'page' => 2]))->assertOk()));
    }

    public function test_the_homepage_has_no_breadcrumbs(): void
    {
        $this->assertSame([], $this->breadcrumbs($this->get(route('home'))->assertOk()));
    }
}
