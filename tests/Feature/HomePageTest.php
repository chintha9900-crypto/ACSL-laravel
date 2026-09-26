<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
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

    /**
     * The shared public header and footer, on any public page — both must
     * link "Blog" to the real `blog.index` route.
     */
    public function test_the_public_header_and_footer_link_to_the_blog(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $response->assertSee('Blog');

        $blogHref = 'href="'.route('blog.index').'"';
        $content = $response->getContent();
        $headerHtml = substr($content, 0, strpos($content, '</header>'));
        $footerHtml = substr($content, strpos($content, '<footer'));

        $this->assertStringContainsString($blogHref, $headerHtml, 'The header nav must link to the blog.');
        $this->assertStringContainsString($blogHref, $footerHtml, 'The footer must link to the blog.');
    }

    /**
     * Splits the shared public layout into its three separate nav
     * locations, so a link's presence in each can be asserted individually
     * rather than lumped into one "somewhere in the header" check: the
     * desktop `<nav aria-label="Main">`, the mobile disclosure menu
     * (`<details class="group lg:hidden">…</details>`), and the footer.
     *
     * The mobile menu's closing `</details>` is found by searching
     * backwards from `</header>`, not forwards from its own opening tag —
     * the Membership item nests its own `<details>` accordion inside the
     * mobile menu, so the *first* `</details>` after the opening tag would
     * be that inner one, truncating the mobile section before Blog/News/
     * Events/Contact.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function navSections(string $content): array
    {
        $desktopStart = strpos($content, 'aria-label="Main"');
        $desktopEnd = strpos($content, '</nav>', $desktopStart);
        $desktopNavHtml = substr($content, $desktopStart, $desktopEnd - $desktopStart);

        $headerEnd = strpos($content, '</header>');
        $mobileStart = strpos($content, '<details class="group lg:hidden">');
        $mobileEnd = strrpos(substr($content, 0, $headerEnd), '</details>');
        $mobileNavHtml = substr($content, $mobileStart, $mobileEnd - $mobileStart);

        $footerHtml = substr($content, strpos($content, '<footer'));

        return [$desktopNavHtml, $mobileNavHtml, $footerHtml];
    }

    /**
     * The shared public header (desktop nav), mobile menu, and footer, on
     * any public page — all three must have exactly ONE "News & Events"
     * item, linking to the combined landing page, not separate "News" and
     * "Events" entries.
     */
    public function test_news_and_events_is_one_combined_item_in_the_desktop_header_mobile_menu_and_footer(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $newsEventsHref = 'href="'.route('news-events').'"';
        [$desktopNavHtml, $mobileNavHtml, $footerHtml] = $this->navSections($response->getContent());

        foreach (['desktop nav' => $desktopNavHtml, 'mobile menu' => $mobileNavHtml, 'footer' => $footerHtml] as $where => $html) {
            $this->assertStringContainsString($newsEventsHref, $html, "The {$where} must link to news-events.");
            $this->assertStringContainsString('News &amp; Events', $html, "The {$where} must label the link \"News & Events\".");
        }

        $this->assertSame(1, substr_count($desktopNavHtml, $newsEventsHref), 'The desktop nav must have exactly one News & Events link, not separate News/Events links.');
        $this->assertSame(1, substr_count($mobileNavHtml, $newsEventsHref), 'The mobile menu must have exactly one News & Events link, not separate News/Events links.');
    }

    /**
     * Parses a response body for precise structural queries — used here to
     * distinguish a direct top-level nav link from one nested inside the
     * Membership dropdown/accordion, which a plain substring search cannot.
     */
    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /**
     * The desktop nav: "Membership" is a native, click-to-open `<details>`
     * dropdown (not a hover-only panel), Rules and FAQ are no longer
     * standalone top-level links, and the other items are unchanged, direct
     * `<a>` children of `<nav>`.
     */
    public function test_membership_is_a_desktop_click_dropdown_and_rules_and_faq_are_no_longer_top_level(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $triggers = $xpath->query('//nav[@aria-label="Main"]/details[contains(@class, "group")]/summary[contains(., "Membership")]');
        $this->assertSame(1, $triggers->length, 'Membership must be exactly one native <details> dropdown trigger in the desktop nav (click-based, not hover-only).');

        $topLevelHrefs = [];
        foreach ($xpath->query('//nav[@aria-label="Main"]/a') as $link) {
            $topLevelHrefs[] = $link->getAttribute('href');
        }

        $this->assertNotContains(route('rules'), $topLevelHrefs, 'Rules must not be a standalone top-level desktop nav link.');
        $this->assertNotContains(route('faq'), $topLevelHrefs, 'FAQ must not be a standalone top-level desktop nav link.');
        $this->assertContains(route('home'), $topLevelHrefs);
        $this->assertContains(route('about'), $topLevelHrefs);
        $this->assertContains(route('blog.index'), $topLevelHrefs);
        $this->assertContains(route('news-events'), $topLevelHrefs);
        $this->assertContains(route('contact'), $topLevelHrefs);
    }

    /**
     * The desktop Membership dropdown panel contains exactly the four
     * requested submenu items, each linking to its real, existing route.
     */
    public function test_the_desktop_membership_dropdown_has_the_four_submenu_items(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $items = [];
        foreach ($xpath->query('//nav[@aria-label="Main"]//div[@role="menu"]/a') as $link) {
            $items[trim($link->textContent)] = $link->getAttribute('href');
        }

        $this->assertSame([
            'Membership Benefits' => route('membership.benefits'),
            'Club Rules' => route('rules'),
            'Become a Member' => route('membership.apply'),
            'FAQ' => route('faq'),
        ], $items);
    }

    /**
     * The mobile menu: "Membership" is its own nested accordion
     * (`<details>`), using the same disclosure pattern as the outer mobile
     * menu, containing the same four submenu items.
     */
    public function test_the_mobile_menu_has_a_membership_accordion_with_the_four_submenu_items(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $accordions = $xpath->query('//details[contains(@class, "group/submenu")]/summary[contains(., "Membership")]');
        $this->assertSame(1, $accordions->length, 'The mobile menu must have exactly one Membership accordion.');

        $items = [];
        foreach ($xpath->query('//details[contains(@class, "group/submenu")]//a') as $link) {
            $items[trim($link->textContent)] = $link->getAttribute('href');
        }

        $this->assertSame([
            'Membership Benefits' => route('membership.benefits'),
            'Club Rules' => route('rules'),
            'Become a Member' => route('membership.apply'),
            'FAQ' => route('faq'),
        ], $items);
    }

    /**
     * The footer's "Explore" column: Membership shows as one normal link
     * (to `membership.benefits`, not a dropdown and not its four children),
     * and News & Events shows as its one combined item — never the two
     * separate full-list routes.
     */
    public function test_the_footer_explore_column_shows_membership_as_one_link_not_four(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $exploreHeading = $xpath->query('//h4[contains(., "Explore")]')->item(0);
        $this->assertNotNull($exploreHeading, 'The footer must have an "Explore" column.');

        $items = [];
        foreach ($xpath->query('following-sibling::ul[1]/li/a', $exploreHeading) as $link) {
            $items[trim($link->textContent)] = $link->getAttribute('href');
        }

        $this->assertSame(route('membership.benefits'), $items['Membership'] ?? null, 'Footer "Membership" must be one plain link, not its dropdown children.');
        $this->assertArrayNotHasKey('Membership Benefits', $items, 'The footer must not list Membership\'s children separately.');
        $this->assertArrayNotHasKey('Club Rules', $items);
        $this->assertArrayNotHasKey('Become a Member', $items);
        $this->assertArrayNotHasKey('FAQ', $items);

        $this->assertSame(route('news-events'), $items['News & Events'] ?? null, 'Footer must link "News & Events" to the combined landing page.');
        $this->assertArrayNotHasKey('News', $items);
        $this->assertArrayNotHasKey('Events', $items);
    }

    /**
     * The footer has the four documented columns: brand, Explore, Legal,
     * and Get in Touch (which links to the real contact page rather than
     * inventing an email/phone/address that doesn't exist anywhere in this
     * project).
     */
    public function test_the_footer_has_the_four_documented_columns(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame(1, $xpath->query('//footer//h4[contains(., "Explore")]')->length);
        $this->assertSame(1, $xpath->query('//footer//h4[contains(., "Legal")]')->length);

        $getInTouchHeading = $xpath->query('//footer//h4[contains(., "Get in Touch")]')->item(0);
        $this->assertNotNull($getInTouchHeading, 'The footer must have a "Get in Touch" column.');

        $getInTouchLinks = $xpath->query('following-sibling::ul[1]/li/a[@href="'.route('contact').'"]', $getInTouchHeading);
        $this->assertSame(1, $getInTouchLinks->length, 'The Get in Touch column must link to the real contact page.');
    }
}
