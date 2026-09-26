<?php

namespace Tests\Feature;

use App\Models\MembershipSetting;
use Tests\MysqlTestCase;

class FaqPageTest extends MysqlTestCase
{
    public function test_the_faq_page_renders_the_required_topics(): void
    {
        $response = $this->get(route('faq'));

        $response->assertOk();
        $response->assertSee('Who can become a member?');
        $response->assertSee('What are the membership categories?');
        $response->assertSee('What do I need to apply?');
        $response->assertSee('How is my application approved?');
        $response->assertSee('Is there really a free introductory period?');
        $response->assertSee('How does payment and renewal work?');
        $response->assertSee('Will my membership number change when I renew?');
        $response->assertSee('How do I set up my account?');
        $response->assertSee('What is the digital membership card and QR code for?');
    }

    public function test_the_introductory_period_is_read_from_settings_not_hard_coded(): void
    {
        MembershipSetting::current()->forceFill(['introductory_period_months' => 9])->save();

        $this->get(route('faq'))
            ->assertOk()
            ->assertSee('free introductory period before any payment is due')
            ->assertDontSee('first 6 months');
    }

    public function test_the_faq_page_does_not_state_a_specific_fee_amount(): void
    {
        $response = $this->get(route('faq'))->assertOk();

        // Fees are per category/plan and shown on the Benefits page — the FAQ
        // must never assert a specific number here.
        $response->assertDontSee('LKR');
        $response->assertDontSee('USD');
    }
}
