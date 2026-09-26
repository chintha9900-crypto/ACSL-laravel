<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_the_home_page_renders_the_aci_home_page(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('Let&#039;s get started', false);
        $response->assertSee('One Community. One Passion.');
        $response->assertSee('Connecting the Global Aviation Community');
        $response->assertSee('Everything you need to grow.');
    }

    public function test_the_home_page_shows_all_six_documented_benefits(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSeeInOrder([
            'Networking',
            'Career Development',
            'Industry News',
            'Community Events',
            'Professional Support',
            'Job Board',
        ]);
    }

    public function test_the_home_page_cta_links_to_an_existing_route(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('href="'.route('membership.apply').'"', false);
        $response->assertSee('Become a Member');
    }

    public function test_the_home_page_does_not_link_to_pages_that_do_not_exist_yet(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertDontSee('href="/blog"', false);
        $response->assertDontSee('href="/membership/benefits"', false);
        $response->assertDontSee('E-Shop');
    }

    /**
     * The shared public header's "Sign In" button, on any public page — it
     * must point at the real `login` route, not somewhere else.
     */
    public function test_the_public_headers_sign_in_button_points_to_the_login_route(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('href="'.route('login').'"', false);
        $response->assertSee('Sign In');
    }
}
