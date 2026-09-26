<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\SubmitPaymentEvidence;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class VerificationControllerTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /**
     * Prove a raw database id is not exposed as the value of an
     * identifier-carrying HTML attribute (a link, form action/value or
     * data-* attribute) or as the entire text of an element — rather than
     * merely absent as a substring, which a coincidental digit match
     * elsewhere on the page would falsely fail. The attribute check is
     * scoped to attributes that could plausibly carry an id in this app
     * (href/action/value/data-*), not every attribute indiscriminately,
     * because this static page's decorative SVG icons use small numeric
     * geometry attributes (e.g. `y1="6"`) that can coincidentally equal a
     * test id without exposing anything.
     */
    private function assertIdNotExposed(int $id, string $html): void
    {
        $needle = (string) $id;

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//@*[name()="href" or name()="action" or name()="value" or starts-with(name(), "data-")]') as $attribute) {
            $this->assertNotSame($needle, trim((string) $attribute->nodeValue), "The [{$attribute->nodeName}] attribute must not expose the raw id {$id}.");
        }

        foreach ($xpath->query('//*[not(*)]') as $leaf) {
            $this->assertNotSame($needle, trim((string) $leaf->textContent), "A <{$leaf->nodeName}> element must not render the raw id {$id} as a field value.");
        }
    }

    public function test_a_valid_membership_verifies(): void
    {
        $membership = $this->activatedMembership(['full_name' => 'Nimal Perera']);

        $this->get(route('verification.show', ['token' => $membership->verification_token]))
            ->assertOk()
            ->assertSee('You are a member of Aviation Club International')
            ->assertSee('Nimal Perera')
            ->assertSee($membership->membership_number)
            ->assertSee(config('membership.verification_vendor_url'), false);
    }

    public function test_an_expired_membership_fails_verification(): void
    {
        $membership = $this->activatedMembership(['full_name' => 'Nimal Perera']);
        $membership->terms->first()->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $response = $this->get(route('verification.show', ['token' => $membership->verification_token]))
            ->assertOk();

        $response->assertDontSee('You are a member of Aviation Club International');
        $response->assertDontSee('Nimal Perera');
        $response->assertDontSee($membership->membership_number);
    }

    public function test_an_unknown_token_fails_verification_with_the_same_generic_message_as_an_expired_one(): void
    {
        $membership = $this->activatedMembership();
        $membership->terms->first()->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();
        $expiredResponse = $this->get(route('verification.show', ['token' => $membership->verification_token]));

        $unknownResponse = $this->get(route('verification.show', ['token' => 'not-a-real-token-'.str_repeat('x', 40)]));

        $unknownResponse->assertOk();
        $this->assertSame(
            $expiredResponse->getContent(),
            $unknownResponse->getContent(),
            'An unknown token and an expired one must be indistinguishable to a visitor.'
        );
    }

    public function test_a_tampered_token_fails_safely(): void
    {
        $membership = $this->activatedMembership();
        $tampered = substr($membership->verification_token, 0, -1).'!';

        $this->get(route('verification.show', ['token' => $tampered]))
            ->assertOk()
            ->assertDontSee($membership->membership_number);
    }

    public function test_a_renewed_membership_becomes_valid_again_under_the_same_token(): void
    {
        $membership = $this->renewableMembership(['full_name' => 'Nimal Perera']);
        $membership->terms->first()->forceFill(['starts_on' => now()->subMonths(13), 'expires_on' => now()->subMonth()])->save();
        $token = $membership->verification_token;
        $originalNumber = $membership->membership_number;

        $this->get(route('verification.show', ['token' => $token]))
            ->assertOk()
            ->assertDontSee('You are a member of Aviation Club International');

        $term = app(StartRenewal::class)->handle($membership);
        app(SubmitPaymentEvidence::class)->handle($term->payment, $membership->user, 'REF-1', $this->pdfUpload());
        app(ConfirmPayment::class)->handle($term->payment->fresh(), $this->admin());

        $response = $this->get(route('verification.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('You are a member of Aviation Club International')
            ->assertSee('Nimal Perera');

        $response->assertSee($originalNumber);
        $this->assertSame($originalNumber, $membership->fresh()->membership_number, 'The membership number never changes across a renewal.');
    }

    public function test_public_verification_never_exposes_private_information(): void
    {
        Notification::fake();
        $membership = $this->activatedMembership([
            'full_name' => 'Nimal Perera',
            'email' => 'nimal@example.test',
        ]);

        $response = $this->get(route('verification.show', ['token' => $membership->verification_token]))
            ->assertOk();

        $response->assertDontSee('nimal@example.test');
        $response->assertDontSee($membership->user->password);
        $this->assertIdNotExposed($membership->id, $response->getContent());
        $this->assertIdNotExposed($membership->user_id, $response->getContent());
    }

    public function test_public_verification_is_rate_limited(): void
    {
        $membership = $this->activatedMembership();

        foreach (range(1, 30) as $attempt) {
            $this->get(route('verification.show', ['token' => $membership->verification_token]))->assertOk();
        }

        $this->get(route('verification.show', ['token' => $membership->verification_token]))->assertTooManyRequests();
    }
}
