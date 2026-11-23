<?php

namespace Tests\Feature;

use App\Models\JobListing;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Tests\MysqlTestCase;

/**
 * The public Jobs board (`/jobs`) and each job's own page (`/jobs/{slug}`).
 */
class JobsPageTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', JobListing::DEADLINE_TIMEZONE));
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
    private function listedSlugs(string $html): array
    {
        $slugs = [];
        foreach ($this->xpath($html)->query('//ul[@data-job-list]//a[@data-job-link]') as $link) {
            $slugs[] = $link->getAttribute('data-job-slug');
        }

        return $slugs;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function liveJob(array $attributes = []): JobListing
    {
        return JobListing::factory()->published()->deadline('2026-03-31')->create($attributes);
    }

    // --- listing and visibility ----------------------------------------------

    public function test_only_publicly_visible_jobs_are_listed(): void
    {
        $live = $this->liveJob(['title' => 'Live role']);
        $today = JobListing::factory()->published()->deadline('2026-03-10')->create(['title' => 'Closes today']);
        JobListing::factory()->deadline('2026-03-31')->create(['title' => 'Draft role']);
        JobListing::factory()->expired()->create(['title' => 'Expired role']);
        JobListing::factory()->published()->deadline('2026-03-09')->create(['title' => 'Past deadline not yet expired']);

        $html = $this->get(route('jobs'))->assertOk()->getContent();

        $this->assertEqualsCanonicalizing([$live->slug, $today->slug], $this->listedSlugs($html));
        $this->assertStringNotContainsString('Draft role', $html);
        $this->assertStringNotContainsString('Expired role', $html);
        $this->assertStringNotContainsString('Past deadline not yet expired', $html);
    }

    public function test_each_listing_shows_its_summary_fields(): void
    {
        $this->liveJob([
            'title' => 'Licensed Engineer B1',
            'company_name' => 'Example MRO',
            'location' => 'Katunayake',
            'country' => 'Sri Lanka',
            'employment_type' => 'contract',
            'short_description' => 'Line maintenance on A320 family aircraft.',
            'date_posted' => '2026-03-02',
        ]);

        $this->get(route('jobs'))->assertOk()->assertSeeInOrder([
            'Licensed Engineer B1', 'Example MRO', 'Katunayake, Sri Lanka', 'Contract', 'Line maintenance on A320 family aircraft.', 'Posted 2 Mar 2026',
        ]);
    }

    public function test_the_newest_jobs_are_listed_first(): void
    {
        $older = $this->liveJob(['date_posted' => '2026-03-01']);
        $newer = $this->liveJob(['date_posted' => '2026-03-08']);

        $this->assertSame([$newer->slug, $older->slug], $this->listedSlugs($this->get(route('jobs'))->getContent()));
    }

    public function test_an_empty_board_shows_the_no_vacancies_message(): void
    {
        JobListing::factory()->create();

        $this->get(route('jobs'))->assertOk()
            ->assertSee('No current vacancies.')
            ->assertSee('Please check back soon.')
            ->assertDontSee('data-job-panel', false);
    }

    // --- selection and details -----------------------------------------------

    public function test_the_first_job_is_selected_and_its_full_details_shown(): void
    {
        $first = $this->liveJob(['date_posted' => '2026-03-08', 'title' => 'Selected first']);
        $this->liveJob(['date_posted' => '2026-03-01']);

        $xpath = $this->xpath($this->get(route('jobs'))->getContent());

        $current = $xpath->query('//a[@data-job-link][@aria-current="true"]');
        $this->assertSame(1, $current->length);
        $this->assertSame($first->slug, $current->item(0)->getAttribute('data-job-slug'));
        $this->assertSame($first->slug, $xpath->query('//*[@data-job-panel]//*[@data-job-details]')->item(0)->getAttribute('data-job-slug'));
    }

    public function test_the_job_query_parameter_selects_a_listed_job_and_falls_back_safely(): void
    {
        $first = $this->liveJob(['date_posted' => '2026-03-08']);
        $second = $this->liveJob(['date_posted' => '2026-03-01']);
        $hidden = JobListing::factory()->deadline('2026-03-31')->create();

        $panelSlug = fn (array $query) => $this->xpath($this->get(route('jobs', $query))->getContent())
            ->query('//*[@data-job-panel]//*[@data-job-details]')->item(0)->getAttribute('data-job-slug');

        $this->assertSame($second->slug, $panelSlug(['job' => $second->slug]));
        $this->assertSame($first->slug, $panelSlug(['job' => 'no-such-job']));
        $this->assertSame($first->slug, $panelSlug(['job' => $hidden->slug]), 'A draft must never be shown through ?job=.');
    }

    public function test_the_details_panel_shows_every_field_and_skips_empty_ones(): void
    {
        $job = $this->liveJob([
            'title' => 'Cabin Crew Member',
            'company_name' => 'Example Air',
            'category' => 'cabin_crew',
            'employment_type' => 'full_time',
            'location' => 'Colombo',
            'country' => 'Sri Lanka',
            'location_type' => 'local',
            'salary' => 'LKR 150,000 per month',
            'date_posted' => '2026-03-05',
            'description' => '<p>About the role.</p>',
            'responsibilities' => '<ul><li>Passenger safety</li></ul>',
            'qualifications' => '<p>Diploma</p>',
            'experience' => null,
            'additional_requirements' => null,
        ]);

        $response = $this->get(route('jobs.show', $job))->assertOk();

        $response->assertSeeInOrder([
            'Cabin Crew Member', 'Example Air', 'Colombo, Sri Lanka', 'Local',
            'Full-time', 'Cabin Crew', 'LKR 150,000 per month', '5 Mar 2026', '31 Mar 2026',
            'Job description', 'About the role.', 'Responsibilities', 'Passenger safety', 'Required qualifications', 'Diploma',
        ]);
        $response->assertDontSee('Required experience');
        $response->assertDontSee('Additional requirements');
    }

    public function test_a_job_without_a_salary_shows_no_salary_heading(): void
    {
        $job = $this->liveJob(['salary' => null]);

        $this->get(route('jobs.show', $job))->assertOk()->assertDontSee('Salary');
    }

    public function test_the_company_logo_is_shown_when_present(): void
    {
        Storage::disk('public')->put('jobs/logo.png', 'png');
        $job = $this->liveJob(['company_logo' => 'jobs/logo.png', 'company_name' => 'Logo Air']);

        $this->get(route('jobs.show', $job))->assertSee(Storage::disk('public')->url('jobs/logo.png'))->assertSee('alt="Logo Air logo"', false);
    }

    public function test_rich_text_is_sanitised_and_plain_fields_escaped(): void
    {
        $job = $this->liveJob(['title' => '<b>Bold</b> title']);
        $job->forceFill(['description' => '<p>Safe</p><script>alert("x")</script><img src=x onerror="alert(1)">'])->save();

        $html = $this->get(route('jobs.show', $job))->assertOk()->getContent();

        $this->assertStringContainsString('<p>Safe</p>', $html);
        $this->assertStringNotContainsString('alert("x")', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt; title', $html);
    }

    // --- Apply Now -------------------------------------------------------------

    public function test_apply_now_opens_the_stored_url_in_a_new_tab_safely(): void
    {
        $job = $this->liveJob(['application_url' => 'https://careers.example.com/jobs/42?ref=aci']);

        foreach ([route('jobs'), route('jobs.show', $job)] as $page) {
            $apply = $this->xpath($this->get($page)->getContent())->query('//a[contains(normalize-space(.), "Apply Now")]');

            $this->assertSame(1, $apply->length, "One Apply Now button on {$page}.");
            $this->assertSame('https://careers.example.com/jobs/42?ref=aci', $apply->item(0)->getAttribute('href'));
            $this->assertSame('_blank', $apply->item(0)->getAttribute('target'));
            $this->assertSame('noopener noreferrer', $apply->item(0)->getAttribute('rel'));
        }
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function unusableUrls(): array
    {
        return [
            'missing' => [null],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,hi'],
            'no host' => ['https://'],
            'relative' => ['/apply'],
        ];
    }

    /**
     * @dataProvider unusableUrls
     */
    public function test_apply_now_is_hidden_when_the_url_is_missing_or_invalid(?string $url): void
    {
        $job = $this->liveJob();
        $job->forceFill(['application_url' => $url])->save();

        $this->get(route('jobs.show', $job))->assertOk()->assertDontSee('Apply Now');
        $this->get(route('jobs'))->assertOk()->assertDontSee('Apply Now');
    }

    // --- search and filters ---------------------------------------------------

    /**
     * @return array<string, JobListing>
     */
    private function searchFixtures(): array
    {
        return [
            'pilot' => $this->liveJob(['title' => 'Senior Pilot', 'category' => 'pilots', 'employment_type' => 'full_time', 'location_type' => 'local', 'short_description' => 'Fly regional routes.', 'description' => '<p>Turboprop fleet.</p>']),
            'overseasPilot' => $this->liveJob(['title' => 'Captain', 'category' => 'pilots', 'employment_type' => 'contract', 'location_type' => 'overseas', 'short_description' => 'Long-haul widebody.', 'description' => '<p>Based abroad.</p>']),
            'crew' => $this->liveJob(['title' => 'Flight Attendant', 'category' => 'cabin_crew', 'employment_type' => 'part_time', 'location_type' => 'overseas', 'short_description' => 'Customer service in the cabin.', 'description' => '<p>Multilingual preferred.</p>']),
            'engineer' => $this->liveJob(['title' => 'Avionics Technician', 'category' => 'aircraft_engineering_maintenance', 'employment_type' => 'internship', 'location_type' => 'local', 'short_description' => 'Hangar based.', 'description' => '<p>Work on autopilot systems.</p>']),
        ];
    }

    /**
     * @param  array<string, string>  $query
     * @param  list<string>  $expected  Fixture keys.
     */
    private function assertResults(array $query, array $expected): void
    {
        $fixtures = $this->fixtures ??= $this->searchFixtures();
        $slugs = $this->listedSlugs($this->get(route('jobs', $query))->assertOk()->getContent());

        $this->assertEqualsCanonicalizing(
            array_map(fn (string $key) => $fixtures[$key]->slug, $expected),
            $slugs,
            'Query: '.json_encode($query),
        );
    }

    /** @var array<string, JobListing>|null */
    private ?array $fixtures = null;

    public function test_search_matches_title_category_type_and_keywords(): void
    {
        $this->assertResults(['q' => 'captain'], ['overseasPilot']);
        $this->assertResults(['q' => 'Pilots'], ['pilot', 'overseasPilot']);
        $this->assertResults(['q' => 'cabin crew'], ['crew']);
        $this->assertResults(['q' => 'part-time'], ['crew']);
        $this->assertResults(['q' => 'internship'], ['engineer']);
        $this->assertResults(['q' => 'widebody'], ['overseasPilot']);
        $this->assertResults(['q' => 'autopilot'], ['engineer']);
        $this->assertResults(['q' => 'Aircraft Engineering'], ['engineer']);
    }

    public function test_the_location_filters_use_the_stored_classification(): void
    {
        $this->assertResults([], ['pilot', 'overseasPilot', 'crew', 'engineer']);
        $this->assertResults(['location' => 'local'], ['pilot', 'engineer']);
        $this->assertResults(['location' => 'overseas'], ['overseasPilot', 'crew']);
        $this->assertResults(['location' => 'mars'], ['pilot', 'overseasPilot', 'crew', 'engineer']);
    }

    public function test_search_and_location_filter_combine(): void
    {
        $this->assertResults(['q' => 'pilot', 'location' => 'overseas'], ['overseasPilot']);
        $this->assertResults(['q' => 'pilot', 'location' => 'local'], ['pilot', 'engineer']);
        $this->assertResults(['q' => 'cabin', 'location' => 'local'], []);
    }

    public function test_like_wildcards_in_the_search_are_treated_literally(): void
    {
        $this->assertResults(['q' => '%'], []);
        $this->assertResults(['q' => '_'], []);
    }

    public function test_a_search_with_no_results_shows_a_helpful_empty_state(): void
    {
        $this->liveJob();

        $this->get(route('jobs', ['q' => 'zeppelin']))->assertOk()
            ->assertSee('No vacancies match your search.')
            ->assertSee('href="'.route('jobs').'"', false);
    }

    public function test_the_search_term_and_filter_are_kept_across_searches_and_filter_changes(): void
    {
        $this->liveJob(['title' => 'Pilot']);
        $xpath = $this->xpath($this->get(route('jobs', ['q' => 'pilot', 'location' => 'overseas']))->getContent());

        $this->assertSame('pilot', $xpath->query('//input[@name="q"]')->item(0)->getAttribute('value'));
        $this->assertSame('overseas', $xpath->query('//form[@role="search"]//input[@type="hidden"][@name="location"]')->item(0)->getAttribute('value'));

        $filters = [];
        foreach ($xpath->query('//nav[@aria-label="Job location"]/a') as $link) {
            $filters[trim($link->textContent)] = [$link->getAttribute('href'), $link->getAttribute('aria-current')];
        }

        $this->assertSame([
            'All' => [route('jobs', ['q' => 'pilot']), ''],
            'Local' => [route('jobs', ['q' => 'pilot', 'location' => 'local']), ''],
            'Overseas' => [route('jobs', ['q' => 'pilot', 'location' => 'overseas']), 'page'],
        ], $filters);
    }

    public function test_the_all_filter_is_selected_by_default(): void
    {
        $xpath = $this->xpath($this->get(route('jobs'))->getContent());

        $current = $xpath->query('//nav[@aria-label="Job location"]/a[@aria-current="page"]');
        $this->assertSame(1, $current->length);
        $this->assertSame('All', trim($current->item(0)->textContent));
        $this->assertSame(0, $xpath->query('//form[@role="search"]//input[@name="location"]')->length);
    }

    public function test_pagination_keeps_the_search_and_filter(): void
    {
        JobListing::factory()->count(21)->published()->deadline('2026-03-31')->create(['title' => 'Pilot role', 'location_type' => 'local']);

        $this->get(route('jobs', ['q' => 'pilot', 'location' => 'local']))->assertOk()
            ->assertSee(e(route('jobs', ['q' => 'pilot', 'location' => 'local', 'page' => 2])), false);
    }

    // --- individual job pages ---------------------------------------------------

    public function test_a_visible_job_has_its_own_page(): void
    {
        $job = $this->liveJob(['title' => 'Ramp Agent']);

        $this->get('/jobs/'.$job->slug)->assertOk()->assertSee('Ramp Agent')->assertSee('<title>Ramp Agent — Jobs — Aviation Club</title>', false);
    }

    public function test_hidden_jobs_return_404_on_their_own_page(): void
    {
        $draft = JobListing::factory()->deadline('2026-03-31')->create();
        $expired = JobListing::factory()->expired()->create();
        $pastDeadline = JobListing::factory()->published()->deadline('2026-03-09')->create();

        $this->get(route('jobs.show', $draft))->assertNotFound();
        $this->get(route('jobs.show', $expired))->assertNotFound();
        $this->get(route('jobs.show', $pastDeadline))->assertNotFound();
        $this->get('/jobs/no-such-job')->assertNotFound();
        $this->get('/jobs/'.$draft->id)->assertNotFound();
    }

    public function test_a_job_stays_reachable_until_the_end_of_its_deadline_date_in_colombo(): void
    {
        $job = JobListing::factory()->published()->deadline('2026-03-10')->create();

        $this->travelTo(CarbonImmutable::parse('2026-03-10 23:59', JobListing::DEADLINE_TIMEZONE));
        $this->get(route('jobs.show', $job))->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-03-11 00:01', JobListing::DEADLINE_TIMEZONE));
        $this->get(route('jobs.show', $job))->assertNotFound();
        $this->assertSame([], $this->listedSlugs($this->get(route('jobs'))->getContent()));
    }

    public function test_list_links_and_the_back_link_keep_the_search_and_filter(): void
    {
        $job = $this->liveJob(['title' => 'Pilot', 'location_type' => 'overseas']);
        $query = ['q' => 'pilot', 'location' => 'overseas'];

        $link = $this->xpath($this->get(route('jobs', $query))->getContent())->query('//a[@data-job-link]')->item(0);
        $this->assertSame(route('jobs.show', ['job' => $job->slug, ...$query]), $link->getAttribute('href'));
        $this->assertSame(route('jobs', [...$query, 'job' => $job->slug]), $link->getAttribute('data-board-url'));

        $back = $this->xpath($this->get(route('jobs.show', ['job' => $job->slug, ...$query]))->getContent())
            ->query('//a[contains(normalize-space(.), "Back to jobs")]')->item(0);
        $this->assertSame(route('jobs', [...$query, 'job' => $job->slug]), $back->getAttribute('href'));
    }

    public function test_the_jobs_strip_item_stays_active_on_a_job_page(): void
    {
        $job = $this->liveJob();

        $current = $this->xpath($this->get(route('jobs.show', $job))->getContent())->query('//nav[@aria-label="Secondary"]//a[@aria-current="page"]');

        $this->assertSame(1, $current->length);
        $this->assertSame(route('jobs'), $current->item(0)->getAttribute('href'));
    }

    // --- layout and sidebar -------------------------------------------------------

    public function test_the_three_static_career_cards_are_shown_with_their_links(): void
    {
        foreach ([route('jobs'), route('jobs.show', $this->liveJob())] as $page) {
            $xpath = $this->xpath($this->get($page)->getContent());
            $aside = $xpath->query('//aside[@aria-label="Career resources"]')->item(0);
            $this->assertNotNull($aside);

            $headings = [];
            foreach ($xpath->query('.//h2', $aside) as $heading) {
                $headings[] = trim($heading->textContent);
            }
            $this->assertSame(['Career Guidance', 'Career Development Resources', 'For Employers and Aviation Organisations'], $headings);

            $links = [];
            foreach ($xpath->query('.//a', $aside) as $link) {
                $links[] = $link->getAttribute('href');
            }
            $this->assertSame([route('education'), route('membership.benefits'), route('contact')], $links);
        }
    }

    public function test_the_responsive_structure_is_in_place(): void
    {
        $this->liveJob();
        $xpath = $this->xpath($this->get(route('jobs'))->getContent());

        $outer = $xpath->query('//div[contains(@class, "lg:grid-cols-[minmax(0,3fr)_minmax(0,1fr)]")]')->item(0);
        $this->assertNotNull($outer, 'Main board 3fr / sidebar 1fr on desktop.');
        $this->assertSame(['div', 'aside'], array_map(fn ($node) => $node->nodeName, iterator_to_array($xpath->query('./*', $outer))));

        $this->assertNotNull($xpath->query('//div[contains(@class, "md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]")]')->item(0), 'List 1/3 and details 2/3 inside the board.');
        $this->assertStringContainsString('md:overflow-y-auto', $xpath->query('//ul[@data-job-list]')->item(0)->getAttribute('class'));
        $this->assertStringContainsString('hidden', $xpath->query('//*[@data-job-panel]/..')->item(0)->getAttribute('class'), 'Below md the details panel is hidden; the job opens on its own page.');
    }

    public function test_the_secondary_strip_links_to_the_jobs_page(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('href="'.route('jobs').'"', false);
    }
}
