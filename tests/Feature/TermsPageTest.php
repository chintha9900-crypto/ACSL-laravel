<?php

namespace Tests\Feature;

use Tests\TestCase;

class TermsPageTest extends TestCase
{
    public function test_the_terms_page_renders_with_a_pending_content_notice(): void
    {
        $response = $this->get(route('terms'));

        $response->assertOk();
        $response->assertSee('Membership Eligibility');
        $response->assertSee('Governing Law');
        $response->assertSee('being finalised by Aviation Club International');
    }

    public function test_it_does_not_state_any_invented_contractual_provision(): void
    {
        $response = $this->get(route('terms'))->assertOk();

        $response->assertSee('Content for this section will be published once approved');
        $response->assertSee('href="'.route('rules').'"', false);
    }
}
