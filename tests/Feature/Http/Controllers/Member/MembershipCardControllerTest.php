<?php

namespace Tests\Feature\Http\Controllers\Member;

use DOMDocument;
use DOMXPath;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class MembershipCardControllerTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;

    /**
     * Prove a raw database id is not exposed as an HTML attribute value or as
     * the entire text of an element — rather than merely absent as a
     * substring, which a coincidental digit match inside a CSS class, CSRF
     * token or QR width would falsely fail.
     */
    private function assertIdNotExposed(int $id, string $html): void
    {
        $needle = (string) $id;

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//@*') as $attribute) {
            $this->assertNotSame($needle, trim((string) $attribute->nodeValue), "The [{$attribute->nodeName}] attribute must not expose the raw id {$id}.");
        }

        foreach ($xpath->query('//*[not(*)]') as $leaf) {
            $this->assertNotSame($needle, trim((string) $leaf->textContent), "A <{$leaf->nodeName}> element must not render the raw id {$id} as a field value.");
        }
    }

    public function test_an_activated_member_can_access_their_digital_card(): void
    {
        $membership = $this->activatedMembership(['full_name' => 'Nimal Perera']);

        $this->actingAs($membership->user)
            ->get(route('member.membership.card'))
            ->assertOk();
    }

    public function test_the_card_shows_name_number_joined_date_and_valid_until(): void
    {
        $membership = $this->activatedMembership(['full_name' => 'Nimal Perera']);
        $term = $membership->terms->first();

        $this->actingAs($membership->user)
            ->get(route('member.membership.card'))
            ->assertOk()
            ->assertSee('Nimal Perera')
            ->assertSee($membership->membership_number)
            ->assertSee($membership->activated_on->format('j M Y'))
            ->assertSee($term->expires_on->format('j M Y'));
    }

    public function test_the_qr_code_container_carries_only_the_verification_url(): void
    {
        $membership = $this->activatedMembership();

        $response = $this->actingAs($membership->user)->get(route('member.membership.card'))->assertOk();
        $html = $response->getContent();

        $expectedUrl = route('verification.show', ['token' => $membership->verification_token]);

        $this->assertStringContainsString('data-url="'.$expectedUrl.'"', $html);
        // No personal data anywhere in the URL itself.
        $this->assertStringNotContainsString($membership->user->email, $expectedUrl);
        $this->assertStringNotContainsString($membership->membership_number, $expectedUrl);
        $this->assertStringNotContainsString($membership->user->name, $expectedUrl);
    }

    public function test_the_card_never_exposes_email_or_internal_ids(): void
    {
        $membership = $this->activatedMembership(['email' => 'nimal@example.test']);

        $response = $this->actingAs($membership->user)->get(route('member.membership.card'))->assertOk();

        $response->assertDontSee('nimal@example.test');
        $this->assertIdNotExposed($membership->id, $response->getContent());
    }

    public function test_a_lapsed_card_is_marked_not_valid(): void
    {
        $membership = $this->activatedMembership();
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $this->actingAs($membership->user->fresh())
            ->get(route('member.membership.card'))
            ->assertOk()
            ->assertSee('Not valid')
            ->assertSee('not currently valid');
    }

    public function test_a_guest_cannot_view_a_card(): void
    {
        $membership = $this->activatedMembership();

        $this->get(route('member.membership.card'))->assertRedirect(route('login'));
        unset($membership);
    }

    public function test_a_member_cannot_view_another_members_card(): void
    {
        $mine = $this->activatedMembership(['full_name' => 'Nimal Perera', 'email' => 'mine@example.test']);
        $theirs = $this->activatedMembership(['full_name' => 'Kamal Silva', 'email' => 'theirs@example.test'], 'V');

        // The route accepts no id at all, but confirm a spoofed one changes nothing.
        $response = $this->actingAs($mine->user)
            ->get(route('member.membership.card', ['membership' => $theirs->id, 'user' => $theirs->user_id]))
            ->assertOk();

        $response->assertSee($mine->membership_number)->assertSee('Nimal Perera');
        $response->assertDontSee($theirs->membership_number)->assertDontSee('Kamal Silva');
    }
}
