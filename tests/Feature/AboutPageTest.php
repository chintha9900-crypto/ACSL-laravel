<?php

namespace Tests\Feature;

use Tests\TestCase;

class AboutPageTest extends TestCase
{
    public function test_the_about_page_renders(): void
    {
        $response = $this->get(route('about'));

        $response->assertOk();
        $response->assertSee('Connecting the Global Aviation Community');
        $response->assertSee('Our Mission');
        $response->assertSee('Our Vision');
    }

    public function test_the_about_page_cta_links_to_existing_routes_only(): void
    {
        $response = $this->get(route('about'))->assertOk();

        $response->assertSee('href="'.route('membership.apply').'"', false);
        $response->assertSee('href="'.route('membership.benefits').'"', false);
        $response->assertDontSee('href="/contact"', false);
    }
}
