<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ContactControllerTest extends TestCase
{
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

    // --- validation ---------------------------------------------------------

    public function test_empty_submission_reports_every_required_field(): void
    {
        $this->submit([])->assertSessionHasErrors(['name', 'email', 'subject', 'message']);
    }

    public function test_phone_is_optional(): void
    {
        $this->submit($this->payload(['phone' => null]))->assertSessionHasNoErrors();
    }

    public function test_invalid_email_and_phone_are_rejected(): void
    {
        $this->submit($this->payload(['email' => 'not-an-email', 'phone' => 'call me']))
            ->assertSessionHasErrors(['email', 'phone']);
    }

    // --- a valid submission ---------------------------------------------------

    public function test_a_valid_submission_redirects_to_the_success_page(): void
    {
        $this->submit($this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('contact.submitted'));
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
