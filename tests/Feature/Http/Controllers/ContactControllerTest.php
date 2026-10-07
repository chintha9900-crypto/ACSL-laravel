<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\ContactEnquiry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\MysqlTestCase;

/**
 * Submissions now persist to `contact_enquiries`, so this extends the
 * MySQL-backed base class. Real HTTP is blocked, and every reCAPTCHA
 * verification is faked; the reCAPTCHA keys are cleared for each test so a
 * developer's local `.env` can never cause a real call to Google.
 */
class ContactControllerTest extends MysqlTestCase
{
    private const SITEVERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();

        config([
            'services.recaptcha.site_key' => null,
            'services.recaptcha.secret_key' => null,
            'services.recaptcha.hostname' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Nimal Perera',
            'email' => 'nimal@example.test',
            'phone' => '+94 77 123 4567',
            'subject' => 'Question about membership',
            'message' => 'I would like to know more about student membership.',
            ...$overrides,
        ];
    }

    private function submit(array $payload): TestResponse
    {
        return $this->post(route('contact.store'), $payload);
    }

    private function enableRecaptcha(): void
    {
        config([
            'services.recaptcha.site_key' => 'test-site-key',
            'services.recaptcha.secret_key' => 'test-secret-key',
            'services.recaptcha.hostname' => 'aviationclub.test',
        ]);
    }

    private function fakeGoogleVerification(bool $success, string $hostname = 'aviationclub.test'): void
    {
        Http::fake([
            self::SITEVERIFY_URL => Http::response(['success' => $success, 'hostname' => $hostname]),
        ]);
    }

    // --- the page ---------------------------------------------------------

    public function test_the_contact_page_renders_with_the_form_and_contact_details(): void
    {
        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertSee('General Inquiry');
        $response->assertSee('Contact details');
        $response->assertSee('name="name"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="phone"', false);
        $response->assertSee('name="subject"', false);
        $response->assertSee('name="message"', false);
        $response->assertSee('type="submit"', false);
        $response->assertSee('type="reset"', false);
    }

    public function test_the_membership_enquiry_section_has_been_removed(): void
    {
        $response = $this->get(route('contact'))->assertOk();

        // The removed section's own heading/copy is gone. The header/footer's
        // own (unrelated, untouched) Apply/FAQ links are unaffected and still
        // present on every page, so they aren't asserted against here.
        $response->assertDontSee('Membership enquiries');
        $response->assertDontSee('Ready to join, or have a question before you apply?');
    }

    public function test_it_does_not_invent_contact_details(): void
    {
        $response = $this->get(route('contact'))->assertOk();

        $response->assertDontSee('hello@acsl.lk');
        $response->assertDontSee('hello@example.com');
        $response->assertDontSee('+94 11');
        $response->assertDontSee('Colombo, Sri Lanka');
        $response->assertDontSee('ACSL');
        $response->assertSee('To be published by Aviation Club International.');
    }

    public function test_the_recaptcha_widget_is_shown_only_when_a_site_key_is_configured(): void
    {
        $this->get(route('contact'))->assertOk()->assertDontSee('g-recaptcha');

        $this->enableRecaptcha();

        $this->get(route('contact'))
            ->assertOk()
            ->assertSee('class="g-recaptcha"', false)
            ->assertSee('data-sitekey="test-site-key"', false);
    }

    // --- validation ---------------------------------------------------------

    public function test_empty_submission_reports_every_required_field(): void
    {
        $this->submit([])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_phone_is_optional(): void
    {
        $this->submit($this->payload(['phone' => null]))->assertSessionHasNoErrors();
    }

    public function test_invalid_email_and_phone_are_rejected(): void
    {
        $this->submit($this->payload(['email' => 'not-an-email', 'phone' => 'call me']))
            ->assertSessionHasErrors(['email', 'phone']);

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_the_name_is_limited_to_the_documented_column_length(): void
    {
        $this->submit($this->payload(['name' => str_repeat('a', 151)]))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_a_subject_longer_than_200_characters_is_rejected(): void
    {
        $this->submit($this->payload(['subject' => str_repeat('a', 201)]))
            ->assertSessionHasErrors('subject');

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_a_message_longer_than_5000_characters_is_rejected(): void
    {
        $this->submit($this->payload(['message' => str_repeat('a', 5001)]))
            ->assertSessionHasErrors('message');

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    // --- persistence ---------------------------------------------------------

    public function test_a_valid_submission_creates_a_contact_enquiry_row(): void
    {
        $this->submit($this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('contact.submitted'));

        $this->assertSame(1, ContactEnquiry::query()->count());
    }

    public function test_the_stored_enquiry_has_status_new(): void
    {
        $this->submit($this->payload());

        $this->assertSame(ContactEnquiry::STATUS_NEW, ContactEnquiry::query()->firstOrFail()->status);
    }

    public function test_the_required_fields_are_persisted_correctly(): void
    {
        $this->submit($this->payload());

        $enquiry = ContactEnquiry::query()->firstOrFail();
        $this->assertSame('Nimal Perera', $enquiry->name);
        $this->assertSame('nimal@example.test', $enquiry->email);
        $this->assertSame('Question about membership', $enquiry->subject);
        $this->assertSame('I would like to know more about student membership.', $enquiry->message);
        $this->assertSame('+94 77 123 4567', $enquiry->phone);
        $this->assertNull($enquiry->handled_by_user_id);
    }

    public function test_a_missing_phone_is_stored_as_null(): void
    {
        $this->submit($this->payload(['phone' => null]));

        $this->assertNull(ContactEnquiry::query()->firstOrFail()->phone);
    }

    // --- reCAPTCHA ------------------------------------------------------------

    public function test_a_missing_recaptcha_token_is_rejected_when_verification_is_enabled(): void
    {
        $this->enableRecaptcha();
        $this->fakeGoogleVerification(true);

        $this->submit($this->payload())->assertSessionHasErrors('g-recaptcha-response');

        Http::assertNothingSent();
        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_a_failed_recaptcha_verification_is_rejected(): void
    {
        $this->enableRecaptcha();
        $this->fakeGoogleVerification(false);

        $this->submit($this->payload(['g-recaptcha-response' => 'bad-token']))
            ->assertSessionHasErrors('g-recaptcha-response')
            ->assertRedirect();

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_a_successful_recaptcha_verification_permits_submission(): void
    {
        $this->enableRecaptcha();
        $this->fakeGoogleVerification(true);

        $this->submit($this->payload(['g-recaptcha-response' => 'good-token']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('contact.submitted'));

        $this->assertSame(1, ContactEnquiry::query()->count());
    }

    public function test_recaptcha_is_verified_with_the_secret_token_and_visitor_ip(): void
    {
        $this->enableRecaptcha();
        $this->fakeGoogleVerification(true);

        $this->submit($this->payload(['g-recaptcha-response' => 'good-token']));

        Http::assertSent(function ($request) {
            return $request->url() === self::SITEVERIFY_URL
                && $request['secret'] === 'test-secret-key'
                && $request['response'] === 'good-token'
                && filled($request['remoteip']);
        });
    }

    public function test_a_recaptcha_network_failure_rejects_the_submission(): void
    {
        $this->enableRecaptcha();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->submit($this->payload(['g-recaptcha-response' => 'good-token']))
            ->assertSessionHasErrors('g-recaptcha-response');

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_missing_recaptcha_keys_allow_submission_in_local_and_testing(): void
    {
        config(['app.env' => 'testing']);

        $this->submit($this->payload())->assertRedirect(route('contact.submitted'));

        $this->assertSame(1, ContactEnquiry::query()->count());
        Http::assertNothingSent();
    }

    public function test_missing_recaptcha_keys_reject_submission_in_production(): void
    {
        config(['app.env' => 'production']);

        $this->submit($this->payload())->assertSessionHasErrors('g-recaptcha-response');

        $this->assertSame(0, ContactEnquiry::query()->count());
        Http::assertNothingSent();
    }

    public function test_missing_recaptcha_keys_reject_submission_in_staging(): void
    {
        config(['app.env' => 'staging']);

        $this->submit($this->payload())->assertSessionHasErrors('g-recaptcha-response');

        $this->assertSame(0, ContactEnquiry::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_secret_key_without_a_site_key_rejects_submission_cleanly_in_production_and_staging(): void
    {
        $this->enableRecaptcha();
        config(['services.recaptcha.site_key' => null]);

        foreach (['production', 'staging'] as $environment) {
            config(['app.env' => $environment]);

            $this->submit($this->payload(['g-recaptcha-response' => 'good-token']))
                ->assertSessionHasErrors('g-recaptcha-response');
        }

        $this->assertSame(0, ContactEnquiry::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_secret_key_without_an_expected_hostname_rejects_submission(): void
    {
        $this->enableRecaptcha();
        config(['services.recaptcha.hostname' => null]);
        $this->fakeGoogleVerification(true);

        $this->submit($this->payload(['g-recaptcha-response' => 'good-token']))
            ->assertSessionHasErrors('g-recaptcha-response');

        Http::assertNothingSent();
        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_a_recaptcha_response_for_a_different_hostname_is_rejected(): void
    {
        $this->enableRecaptcha();
        $this->fakeGoogleVerification(true, 'another-site.example');

        $this->submit($this->payload(['g-recaptcha-response' => 'good-token']))
            ->assertSessionHasErrors('g-recaptcha-response');

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    public function test_the_recaptcha_hostname_is_compared_case_insensitively(): void
    {
        $this->enableRecaptcha();
        $this->fakeGoogleVerification(true, 'AVIATIONCLUB.test');

        $this->submit($this->payload(['g-recaptcha-response' => 'good-token']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('contact.submitted'));

        $this->assertSame(1, ContactEnquiry::query()->count());
    }

    // --- a valid submission ---------------------------------------------------

    public function test_a_valid_submission_redirects_to_the_success_page(): void
    {
        $this->submit($this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('contact.submitted'));
    }

    public function test_no_admin_email_or_notification_is_sent_yet(): void
    {
        $this->submit($this->payload())->assertRedirect(route('contact.submitted'));

        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_the_success_page_shows_only_the_confirmation_message(): void
    {
        $response = $this->get(route('contact.submitted'));

        $response->assertOk();
        $response->assertSee('Your enquiry has been submitted successfully.');
        $response->assertSee('We will get back to you soon.');
        $response->assertSee('Thank you.');
        $response->assertDontSee('Contact details');
        $response->assertDontSee('General Inquiry');
    }

    public function test_submissions_are_throttled(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->submit([])->assertSessionHasErrors('name');
        }

        $this->submit([])->assertTooManyRequests();
    }
}
