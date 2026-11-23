<?php

namespace Tests\Feature;

use Tests\TestCase;

class EducationPageTest extends TestCase
{
    public function test_the_education_page_renders_its_pathways_and_sidebar(): void
    {
        $response = $this->get(route('education'));

        $response->assertOk();
        $response->assertSeeInOrder(['Flight Training', 'Aircraft Engineering and Maintenance', 'Cabin Crew Training', 'Aviation Operations and Airport Management', 'Other Aviation Learning Opportunities']);
        $response->assertSee('Education resources');
        $response->assertSee('Resources coming soon.');
        $response->assertSee('href="'.route('membership.apply').'"', false);
    }

    public function test_the_secondary_strip_links_to_the_education_page(): void
    {
        $this->get(route('home'))->assertOk()->assertSee('href="'.route('education').'"', false);
    }
}
