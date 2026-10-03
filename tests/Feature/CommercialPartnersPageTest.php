<?php

namespace Tests\Feature;

use App\Models\CommercialPartner;
use Illuminate\Support\Facades\Storage;
use Tests\MysqlTestCase;

/**
 * Phase 1.4C — the page is now DB-backed (it was a static placeholder
 * before), so this now extends `MysqlTestCase` (transactional isolation
 * against the real schema) instead of the bare `Tests\TestCase` it used to:
 * every test here creates `CommercialPartner` rows and must not leak them
 * into other tests.
 */
class CommercialPartnersPageTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

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

    public function test_active_partners_are_displayed(): void
    {
        CommercialPartner::factory()->create(['name' => 'Visible Partner', 'is_active' => true]);

        $this->get(route('commercial-partners'))->assertOk()->assertSee('Visible Partner');
    }

    public function test_inactive_partners_are_not_displayed(): void
    {
        CommercialPartner::factory()->create(['name' => 'Hidden Partner', 'is_active' => false]);

        $this->get(route('commercial-partners'))->assertOk()->assertDontSee('Hidden Partner');
    }

    public function test_display_order_is_respected(): void
    {
        CommercialPartner::factory()->create(['name' => 'Third Partner', 'is_active' => true, 'display_order' => 2]);
        CommercialPartner::factory()->create(['name' => 'First Partner', 'is_active' => true, 'display_order' => 0]);
        CommercialPartner::factory()->create(['name' => 'Second Partner', 'is_active' => true, 'display_order' => 1]);

        $content = $this->get(route('commercial-partners'))->assertOk()->getContent();

        $this->assertTrue(
            strpos($content, 'First Partner') < strpos($content, 'Second Partner')
            && strpos($content, 'Second Partner') < strpos($content, 'Third Partner'),
            'Partners must be ordered by display_order ascending.'
        );
    }

    public function test_name_is_used_as_the_ordering_tie_breaker(): void
    {
        CommercialPartner::factory()->create(['name' => 'Zebra Partner', 'is_active' => true, 'display_order' => 5]);
        CommercialPartner::factory()->create(['name' => 'Alpha Partner', 'is_active' => true, 'display_order' => 5]);

        $content = $this->get(route('commercial-partners'))->assertOk()->getContent();

        $this->assertTrue(
            strpos($content, 'Alpha Partner') < strpos($content, 'Zebra Partner'),
            'Partners sharing the same display_order must be ordered by name ascending.'
        );
    }

    public function test_a_logo_is_displayed_when_available(): void
    {
        Storage::disk('public')->put('partners/test-logo.png', 'fake-image-content');
        CommercialPartner::factory()->create(['name' => 'Logo Partner', 'is_active' => true, 'logo_path' => 'partners/test-logo.png']);

        $response = $this->get(route('commercial-partners'))->assertOk();

        $response->assertSee(Storage::disk('public')->url('partners/test-logo.png'), false);
    }

    public function test_a_missing_logo_is_handled_gracefully(): void
    {
        CommercialPartner::factory()->create(['name' => 'No Logo Partner', 'is_active' => true, 'logo_path' => null]);

        $this->get(route('commercial-partners'))->assertOk()->assertSee('No Logo Partner');
    }

    public function test_the_url_is_linked_when_available(): void
    {
        CommercialPartner::factory()->create(['name' => 'Linked Partner', 'is_active' => true, 'url' => 'https://partner.example.test']);

        $response = $this->get(route('commercial-partners'))->assertOk();

        $response->assertSee('href="https://partner.example.test"', false);
        $response->assertSee('Visit website');
    }

    /**
     * The url is re-validated at render time, not merely trusted because it
     * passed validation on save — a non-http(s) scheme must never become a
     * link target, even if it somehow ended up stored.
     */
    public function test_an_unsafe_url_scheme_is_never_rendered_as_a_link(): void
    {
        $partner = CommercialPartner::factory()->create(['name' => 'Unsafe Url Partner', 'is_active' => true]);
        $partner->forceFill(['url' => 'javascript:alert(1)'])->save();

        $response = $this->get(route('commercial-partners'))->assertOk();

        $response->assertSee('Unsafe Url Partner');
        $response->assertDontSee('javascript:alert', false);
        $response->assertDontSee('Visit website');
    }

    public function test_a_missing_url_is_handled_gracefully(): void
    {
        CommercialPartner::factory()->create(['name' => 'No Url Partner', 'is_active' => true, 'url' => null]);

        $response = $this->get(route('commercial-partners'))->assertOk();

        $response->assertSee('No Url Partner');
        $response->assertDontSee('Visit website');
    }

    public function test_a_description_is_displayed_when_available(): void
    {
        CommercialPartner::factory()->create([
            'name' => 'Described Partner',
            'is_active' => true,
            'description' => 'A genuinely helpful partner description.',
        ]);

        $this->get(route('commercial-partners'))->assertOk()->assertSee('A genuinely helpful partner description.');
    }

    public function test_a_missing_description_is_handled_gracefully(): void
    {
        CommercialPartner::factory()->create(['name' => 'No Description Partner', 'is_active' => true, 'description' => null]);

        $this->get(route('commercial-partners'))->assertOk()->assertSee('No Description Partner');
    }

    public function test_an_empty_state_is_shown_when_there_are_no_active_partners(): void
    {
        CommercialPartner::factory()->create(['is_active' => false]);

        $response = $this->get(route('commercial-partners'))->assertOk();

        $response->assertSee('No commercial partners are listed right now');
        $response->assertDontSee('Partner information coming soon');
    }
}
