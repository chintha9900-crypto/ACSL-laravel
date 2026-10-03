<?php

namespace Tests\Feature;

use Tests\TestCase;

class CommercialPartnersPageTest extends TestCase
{
    public function test_the_commercial_partners_page_renders(): void
    {
        $response = $this->get(route('commercial-partners'));

        $response->assertOk();
        $response->assertSee('Our commercial');
        $response->assertSee('partners.');
    }

    public function test_the_commercial_partners_page_links_to_membership_apply(): void
    {
        $response = $this->get(route('commercial-partners'))->assertOk();

        $response->assertSee('href="'.route('membership.apply').'"', false);
    }
}
