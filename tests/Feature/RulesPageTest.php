<?php

namespace Tests\Feature;

use Tests\TestCase;

class RulesPageTest extends TestCase
{
    public function test_the_rules_page_renders(): void
    {
        $response = $this->get(route('rules'));

        $response->assertOk();
        $response->assertSee('Eligibility');
        $response->assertSee('Code of Conduct');
        $response->assertSee('Member Responsibilities');
        $response->assertSee('Membership Standing');
    }

    public function test_the_rules_page_does_not_claim_a_consent_capture_it_does_not_have(): void
    {
        $response = $this->get(route('rules'))->assertOk();

        $response->assertDontSee('I agree');
    }
}
