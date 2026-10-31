<?php

namespace Tests\Feature\SchoolClub;

use App\Actions\Membership\ActivateMembership;
use App\Models\Document;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\SchoolClubStudentBatch;
use App\Models\User;
use App\Notifications\Membership\ApprovedIntroductory;
use App\Notifications\Membership\StudentBatchReviewed;
use App\Notifications\Membership\Welcome;
use App\Support\SchoolClub\StudentListReader;
use App\Support\SchoolClub\StudentListTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleXMLElement;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesSchoolClubs;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;
use ZipArchive;

/**
 * School Club dashboard and student management improvements: the
 * representative's position, the club identity on dashboards, cards, emails
 * and admin views, the student statistics, the Excel template, and the
 * Excel → printable PDF → principal-signed PDF workflow.
 */
class SchoolClubDashboardTest extends MysqlTestCase
{
    use CreatesReviewableApplications, CreatesSchoolClubs, CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('public');
    }

    private function uploadList(Membership $club, UploadedFile $file): SchoolClubStudentBatch
    {
        $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $file])->assertRedirect()->assertSessionHasNoErrors();

        return SchoolClubStudentBatch::query()->where('membership_id', $club->id)->latest('id')->firstOrFail();
    }

    private function submittedBatch(Membership $club, array $students): SchoolClubStudentBatch
    {
        $batch = $this->uploadList($club, $this->studentCsv($students));
        $this->actingAs($club->user)->post(route('member.school-club.students.signed', $batch), ['principal_list' => $this->pdfUpload('signed.pdf')])->assertRedirect()->assertSessionHasNoErrors();

        return $batch->fresh();
    }

    private function approve(SchoolClubStudentBatch $batch): void
    {
        $this->actingAs($this->admin())->post(route('admin.school-club-batches.review', $batch), ['decision' => 'approved', 'list_reviewed' => '1'])->assertSessionHasNoErrors();
    }

    private function decide(SchoolClubStudentBatch $batch, string $decision): void
    {
        $this->actingAs($this->admin())->post(route('admin.school-club-batches.review', $batch), ['decision' => $decision])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applicationPayload(array $overrides = []): array
    {
        return [
            'category' => 'SC',
            'company_name' => 'Example College',
            'address' => '1 School Lane, Colombo',
            'company_email' => 'office@example-college.test',
            'company_phone' => '+94 11 234 5678',
            'aviation_role' => 'Example College Aviation Club',
            'full_name' => 'Rep Person',
            'email' => 'rep.person@example-college.test',
            'mobile' => '+94 77 123 4567',
            'aviation_organisation' => 'Vice Principal',
            'proof_documents' => [$this->pdfUpload('principal-letter.pdf')],
            'declaration' => '1',
            ...$overrides,
        ];
    }

    // --- position ------------------------------------------------------------------

    public function test_the_application_offers_the_position_dropdown_in_order(): void
    {
        $html = $this->get(route('membership.apply', ['category' => 'school-club']))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<select id="aviation_organisation_school_club" name="aviation_organisation"[^>]*>(.*?)</select>#s', $html, $select));
        preg_match_all('#<option value="([^"]*)"#', $select[1], $options);
        $this->assertSame(['', ...MembershipApplication::SCHOOL_CLUB_POSITIONS], $options[1]);
        $this->assertSame(
            ['Principal', 'Vice Principal', 'President', 'Vice President', 'Secretary', 'Treasurer', 'Teacher in Charge', 'School Administrator', 'Other'],
            MembershipApplication::SCHOOL_CLUB_POSITIONS,
        );
    }

    public function test_the_position_is_required_and_must_be_one_of_the_options(): void
    {
        $this->post(route('membership.apply.store'), $this->applicationPayload(['aviation_organisation' => '']))
            ->assertSessionHasErrors(['aviation_organisation' => 'The position field is required.']);
        $this->post(route('membership.apply.store'), $this->applicationPayload(['aviation_organisation' => 'Club coordinator']))
            ->assertSessionHasErrors(['aviation_organisation' => 'The selected position is invalid.']);
        $this->assertSame(0, MembershipApplication::query()->count());

        $this->post(route('membership.apply.store'), $this->applicationPayload())->assertSessionHasNoErrors();
        $this->assertSame('Vice Principal', MembershipApplication::query()->sole()->aviation_organisation);
    }

    public function test_other_categories_keep_their_free_text_organisation(): void
    {
        $application = $this->application('approved', ['aviation_organisation' => 'Acme Aviation Ltd']);
        $membership = app(ActivateMembership::class)->handle($application, $this->admin())->membership;

        $this->assertNull($membership->load('category')->schoolClubDetails());
    }

    // --- dashboard -------------------------------------------------------------------

    public function test_the_representative_dashboard_shows_position_club_and_school_after_approval(): void
    {
        $club = $this->schoolClub(['aviation_organisation' => 'Principal']);

        $html = $this->actingAs($club->user)->get(route('member.dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<dl[^>]*data-school-club-identity>(.*?)</dl>#s', $html, $identity));
        $this->assertSame('Position: Principal School Club: Example College Aviation Club School: Example College', trim(preg_replace('/\s+/', ' ', strip_tags($identity[1]))));

        $this->assertSame(1, preg_match('#id="membership-heading".*?</dl>#s', $html, $card));
        $text = preg_replace('/\s+/', ' ', strip_tags($card[0]));
        foreach (['Member name Rep Person', 'Membership number '.$club->membership_number, 'Category School Club', 'Membership status Active', 'School Example College', 'School Club Example College Aviation Club', 'Representative Rep Person', 'Position Principal'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_a_student_dashboard_shows_the_club_and_school_from_the_parent_but_no_position(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->submittedBatch($club, [['Stu', 'Dent', 'stu@example.test', '01/02/2011']]));
        $student = User::query()->where('email', 'stu@example.test')->firstOrFail();
        $student->forceFill(['status' => 'active'])->save();

        $html = $this->actingAs($student)->get(route('member.dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<dl[^>]*data-school-club-identity>(.*?)</dl>#s', $html, $identity));
        $this->assertSame('School Club: Example College Aviation Club School: Example College', trim(preg_replace('/\s+/', ' ', strip_tags($identity[1]))));
        $this->assertStringNotContainsString('data-statistic', $html);
        $this->assertStringNotContainsString('Teacher in Charge', $html);
    }

    public function test_other_categories_dashboards_are_unchanged(): void
    {
        $membership = app(ActivateMembership::class)->handle($this->application('approved', ['email' => 'pro@example.test']), $this->admin())->membership;
        $membership->user->forceFill(['status' => 'active'])->save();

        $html = $this->actingAs($membership->user)->get(route('member.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-school-club-identity', $html);
        $this->assertStringNotContainsString('data-statistic', $html);
        $this->assertStringNotContainsString('School Club', $html);
    }

    // --- statistics ------------------------------------------------------------------

    public function test_the_statistics_are_live_and_scoped_to_the_club(): void
    {
        Notification::fake();
        $this->withoutMiddleware(ThrottleRequests::class); // many lists within one minute
        $club = $this->schoolClub(['email' => 'mine@example.test']);
        $other = $this->schoolClub(['email' => 'other@example.test']);

        $statistics = fn (): array => collect(['total', 'active', 'notActivated', 'requests'])->mapWithKeys(function (string $key) use ($club): array {
            $html = $this->actingAs($club->user)->get(route('member.dashboard'))->assertOk()->getContent();
            preg_match('#data-statistic="'.$key.'">.*?<p class="mt-2[^"]*">([\d,]+)</p>#s', $html, $match);

            return [$key => (int) str_replace(',', '', $match[1] ?? '-1')];
        })->all();

        $this->assertSame(['total' => 0, 'active' => 0, 'notActivated' => 0, 'requests' => 0], $statistics());

        // Approved: 3 students — one sets up, one stays pending, one is suspended.
        $this->approve($this->submittedBatch($club, [['Ann One', 'ann@example.test'], ['Ben Two', 'ben@example.test'], ['Cal Three', 'cal@example.test']]));
        User::query()->where('email', 'ann@example.test')->update(['status' => 'active']);
        User::query()->where('email', 'cal@example.test')->update(['status' => 'suspended']);

        // Declined (2), awaiting review (1), returned for more details (1), and a list still waiting for its signed PDF (1).
        $this->decide($this->submittedBatch($club, [['Dee Four', 'dee@example.test'], ['Eve Five', 'eve@example.test']]), 'rejected');
        $this->submittedBatch($club, [['Fay Six', 'fay@example.test']]);
        $returned = $this->submittedBatch($club, [['Gus Seven', 'gus@example.test']]);
        $this->actingAs($this->admin())->post(route('admin.school-club-batches.review', $returned), ['decision' => 'more_details_required', 'request_message' => 'Sign it'])->assertSessionHasNoErrors();
        $this->uploadList($club, $this->studentCsv([['Hal Eight', 'hal@example.test']]));

        // Another club's students never count.
        $this->approve($this->submittedBatch($other, [['Ivy Nine', 'ivy@example.test'], ['Jon Ten', 'jon@example.test']]));

        $this->assertSame(['total' => 3, 'active' => 1, 'notActivated' => 1, 'requests' => 7], $statistics());

        // Live: a student finishing setup moves from Not Activated to Active.
        User::query()->where('email', 'ben@example.test')->update(['status' => 'active']);
        $this->assertSame(['total' => 3, 'active' => 2, 'notActivated' => 0, 'requests' => 7], $statistics());
    }

    public function test_an_expired_student_is_neither_active_nor_not_activated(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->submittedBatch($club, [['Old Kid', 'old@example.test']]));
        $student = Membership::query()->where('parent_membership_id', $club->id)->sole();
        $student->user->forceFill(['status' => 'active'])->save();
        DB::table('membership_terms')->where('membership_id', $student->id)->update(['starts_on' => now()->subYears(2)->toDateString(), 'expires_on' => now()->subYear()->toDateString(), 'status' => 'expired']);

        $html = $this->actingAs($club->user)->get(route('member.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#data-statistic="total">.*?>1</p>#s', $html);
        $this->assertMatchesRegularExpression('#data-statistic="active">.*?>0</p>#s', $html);
        $this->assertMatchesRegularExpression('#data-statistic="notActivated">.*?>0</p>#s', $html);
    }

    // --- membership cards ------------------------------------------------------------

    public function test_the_representative_and_student_cards_show_the_school_club_details(): void
    {
        Notification::fake();
        $club = $this->schoolClub(['aviation_organisation' => 'Secretary']);
        $this->approve($this->submittedBatch($club, [['Stu', 'Dent', 'stu@example.test', '01/02/2011']]));
        $student = User::query()->where('email', 'stu@example.test')->firstOrFail();
        $student->forceFill(['status' => 'active'])->save();
        $studentMembership = Membership::query()->where('user_id', $student->id)->sole();

        $cardText = function (User $user): string {
            $html = $this->actingAs($user)->get(route('member.membership.card'))->assertOk()->getContent();
            $this->assertStringContainsString('id="qr-code" data-url="'.route('verification.show', ['token' => Membership::query()->where('user_id', $user->id)->value('verification_token')]).'"', $html);
            $this->assertSame(1, preg_match('#<dl[^>]*data-school-club-card>(.*?)</dl>#s', $html, $details));

            return trim(preg_replace('/\s+/', ' ', strip_tags($details[1])));
        };

        $this->assertSame('Position Secretary Category School Club School Example College School Club Example College Aviation Club', $cardText($club->user));
        $this->assertSame('Category School Club School Example College School Club Example College Aviation Club', $cardText($student));

        $html = $this->actingAs($student)->get(route('member.membership.card'))->getContent();
        $this->assertStringContainsString('Stu Dent', $html);
        $this->assertStringContainsString($studentMembership->membership_number, $html);
        $this->assertStringContainsString('Valid until', $html);
    }

    public function test_other_categories_cards_are_unchanged(): void
    {
        $membership = app(ActivateMembership::class)->handle($this->application('approved', ['email' => 'pro@example.test']), $this->admin())->membership;
        $membership->user->forceFill(['status' => 'active'])->save();

        $this->actingAs($membership->user)->get(route('member.membership.card'))->assertOk()->assertDontSee('data-school-club-card', false)->assertDontSee('School Club');
    }

    // --- template ----------------------------------------------------------------------

    public function test_the_template_has_exactly_the_four_headings_and_the_sample_row(): void
    {
        $club = $this->schoolClub();

        $this->actingAs($club->user)->get(route('member.school-club.students.index'))->assertOk()->assertSee(route('member.school-club.students.template'));
        $response = $this->actingAs($club->user)->get(route('member.school-club.students.template'))->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="template.xlsx"', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'aci-test-');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $workbook = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
        $this->assertCount(1, $workbook->sheets->sheet, 'Exactly one sheet.');
        $sheet = new SimpleXMLElement($zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        @unlink($path);

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $cells[(string) $cell['r']] = (string) $cell->is->t;
            }
            $rows[] = $cells;
        }

        $this->assertSame([
            ['A1' => 'First name', 'B1' => 'Surname', 'C1' => 'Email', 'D1' => 'DOB'],
            ['A2' => 'John', 'B2' => 'Perera', 'C2' => 'john@example.com', 'D2' => '15/04/2010'],
        ], $rows);
    }

    public function test_the_unchanged_template_imports_nothing(): void
    {
        $club = $this->schoolClub();
        $path = tempnam(sys_get_temp_dir(), 'aci-test-');
        file_put_contents($path, app(StudentListTemplate::class)->contents());
        $this->beforeApplicationDestroyed(fn () => @unlink($path));
        $template = new UploadedFile($path, 'template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $template])
            ->assertSessionHasErrors(['students_file' => 'The student list contains no students. Add one row per student below the headings (the John Perera example row is ignored).']);
        $this->assertSame(0, SchoolClubStudentBatch::query()->count());
    }

    public function test_the_sample_row_is_skipped_and_real_rows_are_imported_with_their_details(): void
    {
        $club = $this->schoolClub();

        $batch = $this->uploadList($club, $this->studentXlsx([
            ['John', 'Perera', 'john@example.com', '15/04/2010'],
            ['Nimal', 'Silva', 'Nimal.Silva@Example.test', '03/11/2009'],
            ['Ama', 'Fernando', 'ama@example.test', 40283], // an Excel date serial: 15/04/2010
        ]));

        $this->assertSame(SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST, $batch->status);
        $this->assertSame(2, $batch->students_count);
        $this->assertSame([
            ['Nimal', 'Silva', 'Nimal Silva', 'nimal.silva@example.test', '2009-11-03'],
            ['Ama', 'Fernando', 'Ama Fernando', 'ama@example.test', '2010-04-15'],
        ], $batch->entries->map(fn ($entry) => [$entry->first_name, $entry->surname, $entry->full_name, $entry->email, $entry->date_of_birth->toDateString()])->all());
        $this->assertSame(Document::KIND_SCHOOL_CLUB_STUDENT_SPREADSHEET, $batch->spreadsheet->kind);
        $this->assertNull($batch->studentList);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_headers_must_match_exactly_and_in_order(): void
    {
        $club = $this->schoolClub();
        $message = 'The first row must contain exactly these column headings, in this order: First name | Surname | Email | DOB. Please download the template and use it unchanged.';

        foreach ([
            ['Surname', 'First name', 'Email', 'DOB'],
            ['First name', 'Surname', 'Email'],
            ['First name', 'Surname', 'Email', 'DOB', 'Phone'],
            ['full_name', 'email', '', ''],
        ] as $header) {
            $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $this->studentXlsx([['Ann', 'One', 'ann@example.test', '01/01/2010']], $header)])
                ->assertSessionHasErrors(['students_file' => $message]);
        }

        // Letter case and surrounding spaces are tolerated.
        $this->uploadList($club, $this->studentXlsx([['Ann', 'One', 'ann@example.test', '01/01/2010']], [' first NAME ', 'surname', 'EMAIL', 'dob']));
        $this->assertSame(1, SchoolClubStudentBatch::query()->count());
    }

    public function test_every_row_is_validated_and_nothing_is_stored_when_any_row_fails(): void
    {
        $club = $this->schoolClub();
        $tomorrow = now(config('membership.business_timezone'))->addDay()->format('d/m/Y');

        $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $this->studentCsv([
            ['Good', 'Kid', 'good@example.test', '01/01/2010'],
            ['', 'Kid', 'nofirst@example.test', '01/01/2010'],
            ['No', '', 'nosurname@example.test', '01/01/2010'],
            ['No', 'Email', '', '01/01/2010'],
            ['Bad', 'Email', 'not-an-email', '01/01/2010'],
            ['Dup', 'Kid', 'GOOD@example.test', '01/01/2010'],
            ['No', 'Dob', 'nodob@example.test', ''],
            ['Iso', 'Date', 'iso@example.test', '2010-04-15'],
            ['Short', 'Date', 'short@example.test', '1/4/2010'],
            ['Feb', 'Thirty', 'feb@example.test', '30/02/2010'],
            ['Future', 'Kid', 'future@example.test', $tomorrow],
            ['Old', 'Kid', 'old@example.test', '31/12/1899'],
            ['සුනිල්', 'Perera', 'sunil@example.test', '01/01/2010'],
        ])])->assertSessionHasErrors('students_file');

        $errors = session('errors')->get('students_file');
        foreach ([
            "Row 3: enter the student's first name.",
            "Row 4: enter the student's surname.",
            "Row 5: enter the student's email address.",
            'Row 6: "not-an-email" is not a valid email address.',
            'Row 7: good@example.test appears more than once (first on row 2). Each student needs a unique email address.',
            "Row 8: enter the student's date of birth (DD/MM/YYYY).",
            'Row 9: "2010-04-15" is not a valid date of birth. Use the format DD/MM/YYYY, for example 15/04/2010.',
            'Row 10: "1/4/2010" is not a valid date of birth. Use the format DD/MM/YYYY, for example 15/04/2010.',
            'Row 11: "30/02/2010" is not a valid date of birth. Use the format DD/MM/YYYY, for example 15/04/2010.',
            "Row 12: the date of birth {$tomorrow} is not accepted. It must be a past date, on or after 01/01/1900.",
            'Row 13: the date of birth 31/12/1899 is not accepted. It must be a past date, on or after 01/01/1900.',
            'Row 14: the first name must be written in English letters only (spaces, hyphens, apostrophes and accented letters are allowed). Sinhala and Tamil characters are not accepted.',
        ] as $expected) {
            $this->assertContains($expected, $errors);
        }

        $this->assertSame(0, SchoolClubStudentBatch::query()->count());
        $this->assertSame(0, DB::table('school_club_student_entries')->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_student_names_must_be_written_in_english_letters(): void
    {
        $club = $this->schoolClub();
        $message = fn (int $row, string $label): string => "Row {$row}: the {$label} must be written in English letters only (spaces, hyphens, apostrophes and accented letters are allowed). Sinhala and Tamil characters are not accepted.";

        $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $this->studentXlsx([
            ['Nimal', 'Silva', 'nimal@example.test', '01/01/2010'],
            ['සුනිල්', 'Perera', 'sinhala@example.test', '01/01/2010'],
            ['Kumar', 'சுப்பிரமணியம்', 'tamil@example.test', '01/01/2010'],
            ['Sunil සුනිල්', 'Perera', 'mixed1@example.test', '01/01/2010'],
            ['Ravi', 'Kumar சு', 'mixed2@example.test', '01/01/2010'],
            ['Student1', 'Perera', 'digits@example.test', '01/01/2010'],
        ])])->assertSessionHasErrors('students_file');

        $errors = session('errors')->get('students_file');
        $this->assertSame([
            $message(3, 'first name'),
            $message(4, 'surname'),
            $message(5, 'first name'),
            $message(6, 'surname'),
            $message(7, 'first name'),
        ], $errors);
        $this->assertSame(0, SchoolClubStudentBatch::query()->count());

        // Common English-name characters are accepted, from Excel and from CSV.
        $english = [
            ['Nimal', 'Silva', 'nimal@example.test', '01/01/2010'],
            ['Mary-Anne', "O'Brien", 'mary@example.test', '02/02/2010'],
            ['Zoë', 'De la Cruz', 'zoe@example.test', '03/03/2010'],
            ['A. B.', 'Perera’s', 'ab@example.test', '04/04/2010'],
        ];
        $batch = $this->uploadList($club, $this->studentXlsx($english));
        $this->assertSame(['Nimal Silva', "Mary-Anne O'Brien", 'Zoë De la Cruz', 'A. B. Perera’s'], $batch->entries->pluck('full_name')->all());

        $this->uploadList($club, $this->studentCsv($english));
        $this->assertSame(4, $batch->fresh()->entries()->count());
        $this->actingAs($club->user)->get(route('member.school-club.students.printable', $batch))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_values_outside_the_four_columns_are_refused(): void
    {
        $club = $this->schoolClub();

        $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $this->studentXlsx([['Ann', 'One', 'ann@example.test', '01/01/2010', 'extra']])])
            ->assertSessionHasErrors(['students_file' => 'Row 2: only the four template columns (First name, Surname, Email, DOB) may be filled in.']);
    }

    public function test_the_existing_file_type_and_size_limits_still_apply(): void
    {
        $club = $this->schoolClub();

        $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $this->pngUpload('list.png')])->assertSessionHasErrors('students_file');
        $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => UploadedFile::fake()->create('big.xlsx', 2049)])->assertSessionHasErrors('students_file');
        $this->assertSame(0, SchoolClubStudentBatch::query()->count());
    }

    public function test_the_reader_rejects_the_unchanged_template_directly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'aci-test-');
        file_put_contents($path, app(StudentListTemplate::class)->contents());
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        $this->expectException(RuntimeException::class);
        app(StudentListReader::class)->read(new UploadedFile($path, 'template.xlsx', null, null, true));
    }

    public function test_a_new_upload_replaces_the_list_still_waiting_for_its_signed_pdf(): void
    {
        $club = $this->schoolClub();

        $first = $this->uploadList($club, $this->studentCsv([['Ann One', 'ann@example.test']]));
        $second = $this->uploadList($club, $this->studentCsv([['Ben Two', 'ben@example.test'], ['Ann One', 'ann@example.test']]));

        $this->assertTrue($first->is($second));
        $this->assertSame(1, SchoolClubStudentBatch::query()->count());
        $this->assertSame(['ben@example.test', 'ann@example.test'], $second->fresh()->entries->pluck('email')->all());
        $this->assertSame(2, Document::query()->where('school_club_student_batch_id', $first->id)->where('kind', Document::KIND_SCHOOL_CLUB_STUDENT_SPREADSHEET)->count());
    }

    // --- printable PDF and signed upload -----------------------------------------------

    public function test_the_printable_list_contains_the_club_students_and_signature_spaces(): void
    {
        $club = $this->schoolClub();
        $batch = $this->uploadList($club, $this->studentCsv([['Nimal', 'Silva', 'nimal@example.test', '03/11/2009'], ['Zoë', "O'Brien", 'zoe@example.test', '15/04/2010']]));

        $response = $this->actingAs($club->user)->get(route('member.school-club.students.printable', $batch))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        foreach (['Example College Aviation Club', 'Example College', 'School Club', $club->membership_number, 'Nimal', 'Silva', 'nimal@example.test', '03/11/2009', mb_convert_encoding('Zoë', 'Windows-1252', 'UTF-8'), "O'Brien", '15/04/2010', 'First name', 'Surname', 'Email', 'DOB', "Principal's signature", 'School seal', 'Date', 'Number of students: 2'] as $text) {
            $this->assertStringContainsString('('.$text, $pdf, "PDF text: {$text}");
        }

        // Generated on demand: nothing is written to any disk.
        $this->assertSame(1, count(Storage::disk('private')->allFiles()));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_long_list_spans_several_pages(): void
    {
        $club = $this->schoolClub();
        $students = array_map(fn (int $i): array => ['Student', 'Perera', "student{$i}@example.test", '01/01/2010'], range(1, 80));

        $pdf = $this->actingAs($club->user)->get(route('member.school-club.students.printable', $this->uploadList($club, $this->studentCsv($students))))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#/Type /Pages /Kids \[[^\]]+\] /Count [2-9]#', $pdf);
        $this->assertStringContainsString('(student80@example.test)', $pdf);
    }

    public function test_the_signed_pdf_sends_the_list_to_review_and_is_audited(): void
    {
        $club = $this->schoolClub();
        $batch = $this->uploadList($club, $this->studentCsv([['Ann One', 'ann@example.test']]));

        // Not yet reviewable.
        $this->actingAs($this->admin())->get(route('admin.school-club-batches.index'))->assertOk()->assertDontSee($batch->public_id);

        $this->actingAs($club->user)->post(route('member.school-club.students.signed', $batch), ['principal_list' => $this->pdfUpload('signed.pdf')])
            ->assertRedirect(route('member.school-club.students.index'))
            ->assertSessionHas('status');

        $batch->refresh();
        $this->assertSame(SchoolClubStudentBatch::STATUS_SUBMITTED, $batch->status);
        $this->assertSame(Document::DISK_PRIVATE, $batch->studentList->disk);
        $this->assertSame(['school_club.student_list_uploaded', 'school_club.student_batch_submitted'], DB::table('audit_logs')->where('subject_type', 'school_club_student_batch')->where('subject_id', $batch->id)->orderBy('id')->pluck('event')->all());
        $this->actingAs($this->admin())->get(route('admin.school-club-batches.index'))->assertOk()->assertSee($batch->public_id);

        // Only once.
        $this->actingAs($club->user)->post(route('member.school-club.students.signed', $batch), ['principal_list' => $this->pdfUpload('again.pdf')])
            ->assertSessionHasErrors(['principal_list' => 'This student list is not waiting for a signed list.']);
        $this->assertSame(1, Document::query()->where('school_club_student_batch_id', $batch->id)->where('kind', Document::KIND_SCHOOL_CLUB_STUDENT_LIST)->count());
    }

    public function test_the_documents_are_private_and_only_for_the_representative_and_reviewers(): void
    {
        $club = $this->schoolClub();
        $batch = $this->submittedBatch($club, [['Ann One', 'ann@example.test']]);

        foreach ([$batch->spreadsheet, $batch->studentList] as $document) {
            $this->assertSame('private', $document->disk);
            $this->actingAs($club->user)->get(route('member.documents.show', $document))->assertOk();
            $this->actingAs($this->editor())->get(route('admin.documents.show', $document))->assertOk();
            $this->actingAs($this->dev())->get(route('admin.documents.show', $document))->assertForbidden();
            auth()->logout();
            $this->get(route('member.documents.show', $document))->assertRedirect(route('login'));
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --- admin review -------------------------------------------------------------------

    public function test_the_admin_review_shows_the_club_representative_documents_and_students(): void
    {
        Notification::fake();
        $club = $this->schoolClub(['aviation_organisation' => 'Treasurer']);
        $batch = $this->submittedBatch($club, [['Nimal', 'Silva', 'nimal@example.test', '03/11/2009'], ['Ama', 'Fernando', 'ama@example.test', '15/04/2010']]);

        $html = $this->actingAs($this->editor())->get(route('admin.school-club-batches.show', $batch))->assertOk()->getContent();
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        foreach (['Membership Category School Club', 'School Example College', 'School Club Example College Aviation Club', 'Representative Rep Person', 'Representative position Treasurer', 'Uploaded Excel list: students.csv', 'Principal-signed student list: signed.pdf', '1 Nimal Silva nimal@example.test 03/11/2009', '2 Ama Fernando ama@example.test 15/04/2010', 'Accept', 'Request More Details', 'Decline'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertStringContainsString(route('admin.documents.show', $batch->spreadsheet), $html);
        $this->assertStringContainsString(route('admin.documents.show', $batch->studentList), $html);
        $this->actingAs($this->admin())->get(route('admin.school-club-batches.index'))->assertOk()->assertSee('Example College Aviation Club');

        $this->approve($batch);
        User::query()->where('email', 'nimal@example.test')->update(['status' => 'active']);

        $text = preg_replace('/\s+/', ' ', strip_tags($this->actingAs($this->editor())->get(route('admin.school-club-batches.show', $batch))->getContent()));
        $this->assertStringContainsString('1 active &middot; 1 not activated (account setup pending)', $text);
        $this->assertMatchesRegularExpression('#Nimal Silva nimal@example.test 03/11/2009 SC\d{8}/001 Active#', $text);
        $this->assertMatchesRegularExpression('#Ama Fernando ama@example.test 15/04/2010 SC\d{8}/002 Not activated#', $text);
    }

    public function test_approval_keeps_the_existing_numbering_and_account_creation(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->submittedBatch($club, [['Nimal', 'Silva', 'nimal@example.test', '03/11/2009']]));

        $student = Membership::query()->where('parent_membership_id', $club->id)->with('user')->sole();
        $this->assertSame($club->membership_number.'/001', $student->membership_number);
        $this->assertSame('Nimal Silva', $student->user->name);
        $this->assertSame('pending_setup', $student->user->status);
        $this->assertTrue($student->hasCurrentTerm());
    }

    // --- emails -------------------------------------------------------------------------

    public function test_school_club_emails_show_the_category_school_and_club(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $batch = $this->submittedBatch($club, [['Nimal', 'Silva', 'nimal@example.test', '03/11/2009']]);
        $this->approve($batch);
        $student = User::query()->where('email', 'nimal@example.test')->firstOrFail();
        $identity = 'Membership Category: School Club<br>'."\n".'School: Example College<br>'."\n".'School Club: Example College Aviation Club';

        Notification::assertSentTo($student, Welcome::class, function (Welcome $notification) use ($student, $identity): bool {
            $mail = $notification->toMail($student);
            $text = view($mail->view[1], $mail->viewData)->render();

            return str_contains($mail->render(), $identity)
                && str_contains($text, "Membership Category: School Club\nSchool: Example College\nSchool Club: Example College Aviation Club");
        });
        Notification::assertSentTo($club->user, StudentBatchReviewed::class, fn (StudentBatchReviewed $notification): bool => str_contains($notification->toMail($club->user)->render(), $identity));

        $application = $this->application('submitted', ['email' => 'new-rep@example.test', 'company_name' => 'Hill School', 'aviation_role' => 'Hill Flyers'], 'SC');
        $html = (new ApprovedIntroductory($application))->toMail($application)->render();
        $this->assertStringContainsString('Membership Category: School Club<br>'."\n".'School: Hill School<br>'."\n".'School Club: Hill Flyers', $html);
    }

    public function test_other_categories_emails_are_unchanged(): void
    {
        $membership = app(ActivateMembership::class)->handle($this->application('approved', ['email' => 'pro@example.test']), $this->admin())->membership;
        $mail = (new Welcome($membership->load(['category', 'terms'])))->toMail($membership->user);
        $html = $mail->render();
        $text = view($mail->view[1], $mail->viewData)->render();

        $this->assertStringContainsString('Category: '.$membership->category->name, $html);
        $this->assertStringContainsString("Category: {$membership->category->name}\n", $text);
        foreach ([$html, $text] as $body) {
            $this->assertStringNotContainsString('Membership Category', $body);
            $this->assertStringNotContainsString('School Club', $body);
        }

        $application = $this->application('submitted', ['email' => 'pro2@example.test']);
        $this->assertStringNotContainsString('Membership Category', (new ApprovedIntroductory($application))->toMail($application)->render());
    }
}
