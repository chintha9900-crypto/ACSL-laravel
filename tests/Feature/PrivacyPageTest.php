<?php

namespace Tests\Feature;

use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    public function test_the_privacy_page_renders_with_a_pending_content_notice(): void
    {
        $response = $this->get(route('privacy'));

        $response->assertOk();
        $response->assertSee('Information We Collect');
        $response->assertSee('Your Rights');
        $response->assertSee('being finalised by Aviation Club International');
    }

    public function test_it_does_not_state_any_invented_legal_claim(): void
    {
        $response = $this->get(route('privacy'))->assertOk();

        // No fabricated compliance/certification claims, and every section is
        // explicitly marked as not-yet-approved content.
        $response->assertDontSee('GDPR');
        $response->assertDontSee('Cookie');
        $response->assertDontSee('cookie');
        $response->assertSee('Content for this section will be published once approved');
    }
}
