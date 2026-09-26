<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class MembershipApplicationControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /**
     * @return array<string, string>
     */
    private function urls(): array
    {
        $application = $this->application();
        $document = $this->proofDocument($application);

        return [
            'list' => route('admin.membership-applications.index'),
            'detail' => route('admin.membership-applications.show', $application),
            'document' => route('admin.documents.show', $document),
        ];
    }

    // --- authorization --------------------------------------------------------

    public function test_guests_are_redirected_to_login_from_every_admin_page(): void
    {
        foreach ($this->urls() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->assertGuest();
    }

    public function test_signed_in_non_admins_are_forbidden_from_every_admin_page(): void
    {
        $urls = $this->urls();
        $this->actingAs($this->member());

        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_suspended_admin_is_signed_out_and_denied(): void
    {
        $suspended = User::factory()->suspended()->create(['role' => 'admin']);

        $this->actingAs($suspended)->get($this->urls()['list'])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_admins_can_open_the_list_detail_and_document(): void
    {
        $urls = $this->urls();
        $this->actingAs($this->admin());

        $this->get($urls['list'])->assertOk();
        $this->get($urls['detail'])->assertOk();
        $this->get($urls['document'])->assertOk();
    }

    public function test_numeric_ids_do_not_open_an_application_or_document(): void
    {
        $application = $this->application();
        $document = $this->proofDocument($application);
        $this->actingAs($this->admin());

        $this->get('/admin/membership-applications/'.$application->id)->assertNotFound();
        $this->get('/admin/documents/'.$document->id)->assertNotFound();
    }

    // --- list -----------------------------------------------------------------

    public function test_list_shows_reference_name_category_date_and_status_and_filters_by_status(): void
    {
        $submitted = $this->application(attributes: ['full_name' => 'Submitted Person', 'submitted_at' => '2026-09-15 10:00:00']);
        $rejected = $this->application('rejected', ['full_name' => 'Declined Person']);
        $this->actingAs($this->admin());

        $this->get(route('admin.membership-applications.index'))
            ->assertOk()
            ->assertSee($submitted->public_id)
            ->assertSee('Submitted Person')
            ->assertSee('Professional')
            ->assertSee('15 Sep 2026')
            ->assertSee('Submitted')
            ->assertSee('Declined Person')
            ->assertSee('Declined');

        $this->get(route('admin.membership-applications.index', ['status' => 'rejected']))
            ->assertSee($rejected->public_id)
            ->assertDontSee($submitted->public_id);

        $this->get(route('admin.membership-applications.index', ['status' => 'not-a-status']))
            ->assertSee($rejected->public_id)
            ->assertSee($submitted->public_id);
    }

    // --- detail and documents -------------------------------------------------

    public function test_detail_shows_applicant_information_and_category_specific_fields(): void
    {
        $student = $this->application(attributes: [
            'full_name' => 'Student Person', 'email' => 'student@example.test', 'mobile' => '+94 71 000 1111',
            'address' => '1 Campus Road', 'aviation_role' => 'Aircraft Maintenance', 'aviation_organisation' => 'Aviation Institute',
            'study_start_date' => '2026-01-15', 'expected_completion_date' => '2027-12-15',
        ], categoryCode: 'S');
        $veteran = $this->application(attributes: ['years_experience' => 25, 'previous_employers' => 'Airline A; Airline B'], categoryCode: 'V');
        $this->actingAs($this->admin());

        $this->get(route('admin.membership-applications.show', $student))
            ->assertOk()
            ->assertSee('Student Person')
            ->assertSee('student@example.test')
            ->assertSee('+94 71 000 1111')
            ->assertSee('1 Campus Road')
            ->assertSee('Aircraft Maintenance')
            ->assertSee('Aviation Institute')
            ->assertSee('15 January 2026')
            ->assertSee('15 December 2027')
            ->assertSee('Student')
            ->assertDontSee('Years of experience');

        $this->get(route('admin.membership-applications.show', $veteran))
            ->assertSee('Years of experience')
            ->assertSee('25')
            ->assertSee('Airline A; Airline B')
            ->assertSee('Veteran');
    }

    public function test_detail_lists_documents_with_a_secure_link_and_never_the_storage_path(): void
    {
        $application = $this->application();
        $document = $this->proofDocument($application);
        $this->actingAs($this->admin());

        $this->get(route('admin.membership-applications.show', $application))
            ->assertSee('employment-letter.pdf')
            ->assertSee(route('admin.documents.show', $document), false)
            ->assertDontSee($document->storage_path)
            ->assertDontSee('aviation-proof')
            ->assertDontSee('storage/');
    }

    public function test_admin_downloads_the_document_as_an_attachment_and_the_open_is_audited(): void
    {
        $application = $this->application();
        $document = $this->proofDocument($application, "%PDF-1.4\nsecret proof\n");
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get(route('admin.documents.show', $document))->assertOk();

        $this->assertSame("%PDF-1.4\nsecret proof\n", $response->streamedContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('employment-letter.pdf', $response->headers->get('Content-Disposition'));
        $response->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');

        $audit = DB::table('audit_logs')->where('event', 'document.viewed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($admin->id, $audit[0]->user_id);
        $this->assertSame($document->id, $audit[0]->subject_id);
        $this->assertSame('document', $audit[0]->subject_type);
    }

    public function test_unauthorised_users_cannot_download_and_nothing_is_audited(): void
    {
        $document = $this->proofDocument($this->application());
        $url = route('admin.documents.show', $document);

        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->member())->get($url)->assertForbidden();

        $this->assertSame(0, DB::table('audit_logs')->where('event', 'document.viewed')->count());
    }

    public function test_purged_or_missing_files_are_not_found(): void
    {
        $application = $this->application();
        $purged = $this->proofDocument($application, attributes: ['purged_at' => now(), 'purged_by_user_id' => $this->admin()->id]);
        $missing = $this->proofDocument($application);
        Storage::disk('private')->delete($missing->storage_path);
        $this->actingAs($this->admin());

        $this->get(route('admin.documents.show', $purged))->assertNotFound();
        $this->get(route('admin.documents.show', $missing))->assertNotFound();
    }
}
