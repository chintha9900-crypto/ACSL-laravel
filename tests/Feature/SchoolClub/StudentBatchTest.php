<?php

namespace Tests\Feature\SchoolClub;

use App\Actions\Membership\ActivateMembership;
use App\Actions\SchoolClub\ReviewStudentBatch;
use App\Exceptions\StudentBatchCannotBeReviewedException;
use App\Models\AccountSetupToken;
use App\Models\Document;
use App\Models\Membership;
use App\Models\MembershipTerm;
use App\Models\SchoolClubStudentBatch;
use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use App\Notifications\Membership\StudentBatchReviewed;
use App\Notifications\Membership\Welcome;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesSchoolClubs;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class StudentBatchTest extends MysqlTestCase
{
    use CreatesReviewableApplications, CreatesSchoolClubs, CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    /**
     * The whole representative flow: upload the completed template, then (if
     * it was accepted) the principal-signed PDF. Returns the last response.
     */
    private function submit(Membership $club, UploadedFile $list, ?UploadedFile $pdf = null): TestResponse
    {
        $response = $this->actingAs($club->user)->post(route('member.school-club.students.store'), ['students_file' => $list]);
        if (session('errors')?->isNotEmpty()) {
            return $response;
        }

        $batch = SchoolClubStudentBatch::query()
            ->where('membership_id', $club->id)
            ->where('status', SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST)
            ->latest('id')
            ->firstOrFail();

        return $this->actingAs($club->user)->post(route('member.school-club.students.signed', $batch), [
            'principal_list' => $pdf ?? $this->pdfUpload('signed-list.pdf'),
        ]);
    }

    private function batchFor(Membership $club, array $students): SchoolClubStudentBatch
    {
        $this->submit($club, $this->studentCsv($students))->assertSessionHasNoErrors();

        return SchoolClubStudentBatch::query()->where('membership_id', $club->id)->latest('id')->firstOrFail();
    }

    private function approve(SchoolClubStudentBatch $batch, ?User $reviewer = null): TestResponse
    {
        return $this->actingAs($reviewer ?? $this->admin())->post(route('admin.school-club-batches.review', $batch), [
            'decision' => 'approved',
            'list_reviewed' => '1',
        ]);
    }

    // --- access isolation & dashboard restrictions ------------------------------

    public function test_only_the_main_school_club_sees_and_reaches_manage_student_members(): void
    {
        $club = $this->schoolClub();

        $this->actingAs($club->user)->get(route('member.dashboard'))->assertOk()->assertSee('Manage Student Members');
        $this->actingAs($club->user)->get(route('member.school-club.students.index'))->assertOk()->assertSee('Add students');

        // An ordinary member.
        $member = app(ActivateMembership::class)->handle($this->application('approved', ['email' => 'pro@example.test']), $this->admin())->membership;
        $member->user->forceFill(['status' => 'active'])->save();
        $this->actingAs($member->user)->get(route('member.dashboard'))->assertOk()->assertDontSee('Manage Student Members');
        $this->actingAs($member->user)->get(route('member.school-club.students.index'))->assertForbidden();
        $this->actingAs($member->user)->post(route('member.school-club.students.store'), ['students_file' => $this->studentCsv([['A', 'a@example.test']])])->assertForbidden();
        $this->actingAs($member->user)->get(route('member.school-club.students.template'))->assertForbidden();

        // Staff and guests.
        foreach ([$this->admin(), $this->editor(), $this->dev()] as $staff) {
            $this->actingAs($staff)->get(route('member.school-club.students.index'))->assertForbidden();
        }
        auth()->logout();
        $this->get(route('member.school-club.students.index'))->assertRedirect(route('login'));
    }

    public function test_a_student_member_cannot_manage_students(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->batchFor($club, [['Stu Dent', 'stu.dent@example.test']]));
        $student = User::query()->where('email', 'stu.dent@example.test')->firstOrFail();
        $student->forceFill(['status' => 'active'])->save();

        $this->actingAs($student)->get(route('member.dashboard'))->assertOk()->assertDontSee('Manage Student Members');
        $this->actingAs($student)->get(route('member.school-club.students.index'))->assertForbidden();
    }

    public function test_a_representative_cannot_touch_another_schools_batch_or_documents(): void
    {
        $mine = $this->schoolClub(['email' => 'mine@example.test']);
        $theirs = $this->schoolClub(['email' => 'theirs@example.test']);
        $theirBatch = $this->batchFor($theirs, [['Other Student', 'other@example.test']]);
        DB::table('school_club_student_batches')->where('id', $theirBatch->id)->update(['status' => 'more_details_required', 'request_message' => 'x']);

        $this->actingAs($mine->user)
            ->post(route('member.school-club.students.resubmit', $theirBatch), ['students_file' => $this->studentCsv([['Hijack', 'hijack@example.test']])])
            ->assertNotFound();
        $this->actingAs($mine->user)->post(route('member.school-club.students.signed', $theirBatch), ['principal_list' => $this->pdfUpload()])->assertNotFound();
        $this->actingAs($mine->user)->get(route('member.school-club.students.printable', $theirBatch))->assertNotFound();
        $this->actingAs($mine->user)->get(route('member.documents.show', $theirBatch->spreadsheet))->assertForbidden();
        $this->assertSame(1, $theirBatch->entries()->count());

        $this->actingAs($mine->user)->get(route('member.documents.show', $theirBatch->studentList))->assertForbidden();
        $this->actingAs($mine->user)->get(route('member.school-club.students.index'))->assertDontSee('other@example.test')->assertDontSee($theirs->membership_number);
    }

    // --- batch import validation ------------------------------------------------

    public function test_a_csv_batch_is_stored_with_the_principal_list_and_creates_no_accounts(): void
    {
        $club = $this->schoolClub();

        $this->submit($club, $this->studentCsv([['Ann One', 'Ann.One@Example.test'], ['Ben Two', 'ben.two@example.test']]))
            ->assertRedirect(route('member.school-club.students.index'))
            ->assertSessionHas('status');

        $batch = SchoolClubStudentBatch::query()->firstOrFail();
        $this->assertSame('submitted', $batch->status);
        $this->assertSame($club->id, $batch->membership_id);
        $this->assertSame(2, $batch->students_count);
        $this->assertSame(['ann.one@example.test', 'ben.two@example.test'], $batch->entries->pluck('email')->all());
        $this->assertNull($batch->entries->first()->membership_id);

        foreach ([Document::KIND_SCHOOL_CLUB_STUDENT_LIST => $batch->studentList, Document::KIND_SCHOOL_CLUB_STUDENT_SPREADSHEET => $batch->spreadsheet] as $kind => $document) {
            $this->assertSame($kind, $document->kind);
            $this->assertSame('private', $document->disk);
            $this->assertSame(Document::VISIBILITY_OWNER_AND_ADMIN, $document->visibility);
            Storage::disk('private')->assertExists($document->storage_path);
        }

        $this->assertSame(0, User::query()->whereIn('email', ['ann.one@example.test', 'ben.two@example.test'])->count());
        $this->assertSame(0, Membership::query()->whereNotNull('parent_membership_id')->count());
    }

    public function test_an_excel_batch_is_read_too(): void
    {
        $club = $this->schoolClub();

        $this->submit($club, $this->studentXlsx([['Cat Three', 'cat.three@example.test'], ['Dan Four', 'dan.four@example.test']]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['cat.three@example.test', 'dan.four@example.test'], SchoolClubStudentBatch::query()->firstOrFail()->entries->pluck('email')->all());
    }

    public function test_duplicate_and_invalid_rows_are_reported_by_row_and_nothing_is_stored(): void
    {
        $club = $this->schoolClub();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->submit($club, $this->studentCsv([
            ['Ann One', 'ann@example.test'],
            ['Ann Again', 'ANN@example.test'],
            ['', 'noname@example.test'],
            ['Bad Email', 'not-an-email'],
            ['Taken', 'taken@example.test'],
            ['Rep', $club->user->email],
        ]))->assertSessionHasErrors('students_file');

        $errors = session('errors')->get('students_file');
        $this->assertContains('Row 3: ann@example.test appears more than once (first on row 2). Each student needs a unique email address.', $errors);
        $this->assertContains("Row 4: enter the student's first name.", $errors);
        $this->assertContains("Row 4: enter the student's surname.", $errors);
        $this->assertContains('Row 5: "not-an-email" is not a valid email address.', $errors);
        $this->assertContains('Row 6: taken@example.test is already registered or already submitted. Each student needs their own unused email address.', $errors);
        $this->assertSame(0, SchoolClubStudentBatch::query()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_an_email_already_on_another_open_batch_is_refused(): void
    {
        $a = $this->schoolClub(['email' => 'a-rep@example.test']);
        $b = $this->schoolClub(['email' => 'b-rep@example.test']);
        $this->batchFor($a, [['Shared Kid', 'shared@example.test']]);

        $this->submit($b, $this->studentCsv([['Shared Kid', 'shared@example.test']]))->assertSessionHasErrors('students_file');
        $this->assertSame(1, SchoolClubStudentBatch::query()->count());
    }

    public function test_missing_headers_wrong_file_types_and_missing_pdf_are_rejected(): void
    {
        $club = $this->schoolClub();

        $this->submit($club, $this->studentCsv([['A', 'a@example.test']], 'name,mail'))
            ->assertSessionHasErrors(['students_file' => 'The first row must contain exactly these column headings, in this order: First name | Surname | Email | DOB. Please download the template and use it unchanged.']);
        $this->submit($club, $this->pdfUpload('students.pdf'))
            ->assertSessionHasErrors(['students_file' => 'The student list must be the Excel (.xlsx) template, or a CSV file with the same columns.']);
        $this->assertSame(0, SchoolClubStudentBatch::query()->count());

        $this->submit($club, $this->studentCsv([['A', 'a@example.test']]), $this->pngUpload('list.png'))
            ->assertSessionHasErrors(['principal_list' => 'The principal-signed student list must be a PDF file.']);
        $batch = SchoolClubStudentBatch::query()->sole();
        $this->actingAs($club->user)->post(route('member.school-club.students.signed', $batch))
            ->assertSessionHasErrors(['principal_list' => 'Please attach the student list signed by the principal (scanned PDF).']);

        // The list stays waiting for its signed PDF: nothing reaches review.
        $this->assertSame(SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST, $batch->fresh()->status);
        $this->assertNull($batch->studentList);
    }

    // --- admin review, notifications, numbering --------------------------------

    public function test_batches_appear_for_admin_and_editor_review_but_not_for_dev(): void
    {
        $club = $this->schoolClub();
        $batch = $this->batchFor($club, [['Ann One', 'ann@example.test']]);

        $this->actingAs($this->admin())->get(route('admin.school-club-batches.index'))->assertOk()->assertSee($batch->public_id)->assertSee('Example College');
        $this->actingAs($this->editor())->get(route('admin.school-club-batches.show', $batch))->assertOk()->assertSee('ann@example.test')->assertSee('Accept');
        $this->actingAs($this->editor())->get(route('admin.documents.show', $batch->studentList))->assertOk();
        $this->actingAs($this->dev())->get(route('admin.school-club-batches.index'))->assertForbidden();
        $this->actingAs($this->dev())->post(route('admin.school-club-batches.review', $batch), ['decision' => 'approved', 'list_reviewed' => '1'])->assertForbidden();
        $this->assertSame('submitted', $batch->fresh()->status);
    }

    public function test_approval_creates_linked_free_student_memberships_numbered_from_the_parent(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $batch = $this->batchFor($club, [['Ann One', 'ann@example.test'], ['Ben Two', 'ben@example.test']]);

        $this->approve($batch)->assertRedirect(route('admin.school-club-batches.show', $batch))->assertSessionHas('status');

        $students = $club->students()->with(['user', 'terms'])->get();
        $this->assertSame([$club->membership_number.'/001', $club->membership_number.'/002'], $students->pluck('membership_number')->all());
        $this->assertSame(2, $club->fresh()->last_student_sequence);

        foreach ($students as $student) {
            $this->assertSame($club->id, $student->parent_membership_id);
            $this->assertNull($student->membership_application_id);
            $this->assertSame($club->membership_category_id, $student->membership_category_id);
            $this->assertSame('pending_setup', $student->user->status);
            $this->assertSame('member', $student->user->role);
            $this->assertNull(DB::table('users')->where('id', $student->user->id)->value('password'));
            $this->assertSame(MembershipTerm::PAYMENT_NOT_REQUIRED, $student->terms->first()->payment_status);
            $this->assertSame(12, $student->terms->first()->duration_months);
            $this->assertSame(1, AccountSetupToken::query()->where('user_id', $student->user->id)->whereNull('used_at')->count());
            Notification::assertSentTo($student->user, AccountSetup::class);
            Notification::assertSentTo($student->user, Welcome::class);
        }

        $this->assertSame('approved', $batch->fresh()->status);
        $this->assertSame(0, DB::table('payments')->count());
        Notification::assertSentTo($club->user, StudentBatchReviewed::class, fn (StudentBatchReviewed $n) => str_contains($n->toMail($club->user)->render(), '2 student members have been added'));
    }

    public function test_numbering_continues_across_batches_and_is_unique(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->batchFor($club, [['A', 'a@example.test'], ['B', 'b@example.test']]));
        $this->approve($this->batchFor($club, [['C', 'c@example.test']]));

        $this->assertSame(
            [$club->membership_number.'/001', $club->membership_number.'/002', $club->membership_number.'/003'],
            $club->students()->pluck('membership_number')->all()
        );

        // The database refuses a duplicate student number for the same club.
        $this->expectException(QueryException::class);
        DB::table('memberships')->where('membership_number', $club->membership_number.'/003')->update(['student_sequence' => 2, 'membership_number' => $club->membership_number.'/002']);
    }

    public function test_the_fiftieth_student_is_numbered_050(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        DB::table('memberships')->where('id', $club->id)->update(['last_student_sequence' => 49]);

        $this->approve($this->batchFor($club, [['Fifty', 'fifty@example.test']]));

        $this->assertSame($club->membership_number.'/050', $club->students()->value('membership_number'));
    }

    public function test_a_second_decision_on_the_same_batch_is_refused_and_creates_nothing_more(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $batch = $this->batchFor($club, [['A', 'a@example.test']]);
        $this->approve($batch);

        $this->approve($batch)->assertSessionHasErrors('decision');
        $this->assertSame(1, $club->students()->count());
    }

    public function test_approval_fails_cleanly_when_an_email_was_taken_since_submission(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $batch = $this->batchFor($club, [['A', 'a@example.test'], ['B', 'b@example.test']]);
        User::factory()->create(['email' => 'b@example.test']);

        $this->approve($batch)->assertSessionHasErrors('decision');

        $this->assertSame('submitted', $batch->fresh()->status);
        $this->assertSame(0, $club->students()->count());
        $this->assertSame(0, $club->fresh()->last_student_sequence);
        $this->assertSame(0, User::query()->where('email', 'a@example.test')->count());
    }

    public function test_an_inactive_parent_cannot_have_students_activated(): void
    {
        $club = $this->schoolClub();
        $batch = $this->batchFor($club, [['A', 'a@example.test']]);
        DB::table('membership_terms')->where('membership_id', $club->id)->update(['status' => 'expired', 'expired_at' => now()]);

        $this->expectException(StudentBatchCannotBeReviewedException::class);
        app(ReviewStudentBatch::class)->handle($batch, $this->admin(), 'approved');
    }

    public function test_more_details_then_resubmission_then_decline(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $batch = $this->batchFor($club, [['A', 'a@example.test']]);

        $this->actingAs($this->editor())->post(route('admin.school-club-batches.review', $batch), ['decision' => 'more_details_required'])
            ->assertSessionHasErrors('request_message');
        $this->actingAs($this->editor())->post(route('admin.school-club-batches.review', $batch), ['decision' => 'more_details_required', 'request_message' => 'Please include the signature page.', 'note' => 'internal only'])
            ->assertSessionHasNoErrors();

        $this->assertSame('more_details_required', $batch->fresh()->status);
        Notification::assertSentTo($club->user, StudentBatchReviewed::class, function (StudentBatchReviewed $n) use ($club) {
            $html = $n->toMail($club->user)->render();

            return str_contains($html, 'Please include the signature page.') && ! str_contains($html, 'internal only');
        });
        $this->actingAs($club->user)->get(route('member.school-club.students.index'))->assertSee('Please include the signature page.')->assertSee('Upload corrected list');

        $this->actingAs($club->user)->post(route('member.school-club.students.resubmit', $batch), [
            'students_file' => $this->studentCsv([['A', 'a@example.test'], ['B', 'b@example.test']]),
        ])->assertSessionHasNoErrors();
        $this->assertSame(SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST, $batch->fresh()->status);
        $this->actingAs($club->user)->get(route('member.school-club.students.index'))->assertSee('Please include the signature page.')->assertSee('Download printable list (PDF)');

        $this->actingAs($club->user)->post(route('member.school-club.students.signed', $batch), ['principal_list' => $this->pdfUpload('signed-v2.pdf')])
            ->assertSessionHasNoErrors();
        $this->assertSame('submitted', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->entries()->count());

        Notification::fake();
        $this->actingAs($this->admin())->post(route('admin.school-club-batches.review', $batch), ['decision' => 'rejected'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $batch->fresh()->status);
        $this->assertSame(0, $club->students()->count());
        Notification::assertSentTo($club->user, StudentBatchReviewed::class);
    }

    public function test_accepting_requires_confirming_the_principal_list(): void
    {
        $club = $this->schoolClub();
        $batch = $this->batchFor($club, [['A', 'a@example.test']]);

        $this->actingAs($this->admin())->post(route('admin.school-club-batches.review', $batch), ['decision' => 'approved'])
            ->assertSessionHasErrors('list_reviewed');
        $this->assertSame('submitted', $batch->fresh()->status);
    }

    // --- student accounts ----------------------------------------------------------

    public function test_each_student_sets_their_own_password_once_and_gets_their_own_dashboard_and_card(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->batchFor($club, [['Ann One', 'ann@example.test']]));
        $student = User::query()->where('email', 'ann@example.test')->firstOrFail();

        $token = null;
        Notification::assertSentTo($student, AccountSetup::class, function (AccountSetup $n) use (&$token) {
            $token = basename(parse_url($n->setupUrl(), PHP_URL_PATH));

            return true;
        });

        auth()->logout();
        $this->post(route('account.setup.store', $token), ['password' => 'Student-Passw0rd-1', 'password_confirmation' => 'Student-Passw0rd-1'])->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('Student-Passw0rd-1', DB::table('users')->where('id', $student->id)->value('password')));
        $this->post(route('account.setup.store', $token), ['password' => 'Other-Passw0rd-2', 'password_confirmation' => 'Other-Passw0rd-2'])->assertNotFound();

        $this->post(route('login'), ['email' => 'ann@example.test', 'password' => 'Student-Passw0rd-1'])->assertRedirect(route('member.dashboard'));
        $number = $club->membership_number.'/001';
        $this->get(route('member.dashboard'))->assertOk()->assertSee($number)->assertDontSee($club->user->email);
        $this->get(route('member.membership.card'))->assertOk()->assertSee($number)->assertSee('Ann One');
    }

    public function test_a_student_setup_link_expires(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->batchFor($club, [['Ann One', 'ann@example.test']]));
        $student = User::query()->where('email', 'ann@example.test')->firstOrFail();
        $token = null;
        Notification::assertSentTo($student, AccountSetup::class, function (AccountSetup $n) use (&$token) {
            $token = basename(parse_url($n->setupUrl(), PHP_URL_PATH));

            return true;
        });

        $this->travel((int) DB::table('membership_settings')->value('account_setup_token_ttl_hours') + 1)->hours();

        auth()->logout();
        $this->get(route('account.setup', $token))->assertNotFound();
        $this->assertSame('pending_setup', $student->fresh()->status);
    }

    public function test_students_renew_free_of_charge_too(): void
    {
        Notification::fake();
        $club = $this->schoolClub();
        $this->approve($this->batchFor($club, [['Ann One', 'ann@example.test']]));
        $student = User::query()->where('email', 'ann@example.test')->firstOrFail();
        $student->forceFill(['status' => 'active'])->save();
        $term = Membership::query()->where('user_id', $student->id)->firstOrFail()->terms()->first();

        $this->travelTo($term->expires_on->copy()->subDays(5));
        $this->actingAs($student)->post(route('member.membership.renewal.start'))->assertSessionHasNoErrors();

        $this->assertSame(MembershipTerm::KIND_FREE_RENEWAL, Membership::query()->where('user_id', $student->id)->firstOrFail()->terms()->where('term_no', 2)->value('term_kind'));
        $this->assertSame(0, DB::table('payments')->count());
    }
}
