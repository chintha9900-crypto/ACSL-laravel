<?php

namespace App\Http\Controllers\Member;

use App\Actions\SchoolClub\StageStudentList;
use App\Actions\SchoolClub\SubmitStudentBatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\SubmitSignedStudentListRequest;
use App\Http\Requests\Member\UploadStudentListRequest;
use App\Models\Membership;
use App\Models\SchoolClubStudentBatch;
use App\Models\SchoolClubStudentEntry;
use App\Support\SchoolClub\StudentListPdf;
use App\Support\SchoolClub\StudentListTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

/**
 * "Manage Student Members" — only for the signed-in representative of a main
 * School Club membership. Ownership comes solely from the authenticated user
 * (no membership id in any URL); a batch in a URL must belong to that same
 * School Club, so one school can never see or change another's records.
 *
 * Adding students: download the template → upload the completed Excel →
 * print the generated list for the principal → upload the signed PDF, which
 * sends the list to the existing admin review.
 */
class SchoolClubStudentController extends Controller
{
    public function index(Request $request): View
    {
        $membership = $this->ownSchoolClub($request);

        return view('member.school-club.students', [
            'membership' => $membership,
            'batches' => $membership->studentBatches()->with(['studentList', 'spreadsheet'])->get(),
            'students' => $membership->students()->with(['user:id,name,email,status', 'terms'])->get(),
            'canSubmit' => $membership->hasCurrentTerm(),
        ]);
    }

    /**
     * The blank student template (`template.xlsx`).
     */
    public function template(Request $request, StudentListTemplate $template): Response
    {
        $this->ownSchoolClub($request);

        return response($template->contents(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.StudentListTemplate::FILENAME.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Step one: the completed template becomes a list waiting for its signed PDF.
     */
    public function store(UploadStudentListRequest $request, StageStudentList $stage): RedirectResponse
    {
        $membership = $this->ownSchoolClub($request);

        if (! $membership->hasCurrentTerm()) {
            return redirect()->route('member.school-club.students.index')
                ->withErrors(['students_file' => 'Your School Club membership is not currently active. Renew it before adding students.']);
        }

        try {
            $batch = $stage->handle($membership, $request->user(), $request->students(), $request->spreadsheet(), $request->replacingBatch());
        } catch (RuntimeException $exception) {
            return redirect()->route('member.school-club.students.index')->withErrors(['students_file' => $exception->getMessage()]);
        }

        return redirect()->route('member.school-club.students.index')
            ->with('status', "Your list of {$batch->students_count} student(s) is ready. Download the printable list, have the principal sign it and stamp the school seal, then upload the scanned PDF to send it for review.");
    }

    /**
     * A corrected Excel for a list returned for more details, or for a list
     * still waiting for its signed PDF.
     */
    public function resubmit(UploadStudentListRequest $request, SchoolClubStudentBatch $batch, StageStudentList $stage): RedirectResponse
    {
        $membership = $this->ownSchoolClub($request);
        abort_unless($batch->membership_id === $membership->id, 404);

        if (! in_array($batch->status, [SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED, SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST], true)) {
            return redirect()->route('member.school-club.students.index')
                ->withErrors(['students_file' => 'This student list can no longer be changed.']);
        }

        try {
            $stage->handle($membership, $request->user(), $request->students(), $request->spreadsheet(), $batch);
        } catch (RuntimeException $exception) {
            return redirect()->route('member.school-club.students.index')->withErrors(['students_file' => $exception->getMessage()]);
        }

        return redirect()->route('member.school-club.students.index')
            ->with('status', 'Your corrected student list is ready. Download the new printable list for the principal to sign, then upload the scanned PDF.');
    }

    /**
     * The printable list for the principal to sign, generated from the stored entries.
     */
    public function printableList(Request $request, SchoolClubStudentBatch $batch, StudentListPdf $pdf): Response
    {
        $membership = $this->ownSchoolClub($request);
        abort_unless($batch->membership_id === $membership->id, 404);

        $details = $membership->schoolClubDetails();
        $contents = $pdf->render([
            'club' => $details['club'],
            'school' => $details['school'],
            'category' => $details['category'],
            'number' => $membership->membership_number,
            'reference' => $batch->public_id,
        ], $batch->entries->map(fn (SchoolClubStudentEntry $entry): array => [
            'first_name' => $entry->first_name ?? $entry->full_name,
            'surname' => $entry->surname ?? '',
            'email' => $entry->email,
            'date_of_birth' => $entry->date_of_birth?->format('d/m/Y') ?? '',
        ])->all());

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="student-list-'.$batch->public_id.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Final step: the scanned, principal-signed PDF sends the list for review.
     */
    public function submitSignedList(SubmitSignedStudentListRequest $request, SchoolClubStudentBatch $batch, SubmitStudentBatch $submit): RedirectResponse
    {
        $membership = $this->ownSchoolClub($request);
        abort_unless($batch->membership_id === $membership->id, 404);

        if (! $membership->hasCurrentTerm()) {
            return redirect()->route('member.school-club.students.index')
                ->withErrors(['principal_list' => 'Your School Club membership is not currently active. Renew it before adding students.']);
        }

        try {
            $submit->handle($membership, $request->user(), $batch, $request->principalList());
        } catch (RuntimeException $exception) {
            return redirect()->route('member.school-club.students.index')->withErrors(['principal_list' => $exception->getMessage()]);
        }

        return redirect()->route('member.school-club.students.index')
            ->with('status', "Your list of {$batch->students_count} student(s) has been submitted for review. Student accounts are created once Aviation Club approves it.");
    }

    private function ownSchoolClub(Request $request): Membership
    {
        $membership = Membership::query()
            ->where('user_id', $request->user()->id)
            ->with(['category', 'terms', 'application', 'user'])
            ->first();

        abort_unless($membership !== null && $membership->isSchoolClubMain(), 403);

        return $membership;
    }
}
