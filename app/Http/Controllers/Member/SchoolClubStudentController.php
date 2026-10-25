<?php

namespace App\Http\Controllers\Member;

use App\Actions\SchoolClub\SubmitStudentBatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\SubmitStudentBatchRequest;
use App\Models\Membership;
use App\Models\SchoolClubStudentBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * "Manage Student Members" — only for the signed-in representative of a main
 * School Club membership. Ownership comes solely from the authenticated user
 * (no membership id in any URL); a batch in a URL must belong to that same
 * School Club, so one school can never see or change another's records.
 */
class SchoolClubStudentController extends Controller
{
    public function index(Request $request): View
    {
        $membership = $this->ownSchoolClub($request);

        return view('member.school-club.students', [
            'membership' => $membership,
            'batches' => $membership->studentBatches()->with('studentList')->get(),
            'students' => $membership->students()->with('user:id,name,email,status')->get(),
            'canSubmit' => $membership->hasCurrentTerm(),
        ]);
    }

    public function store(SubmitStudentBatchRequest $request, SubmitStudentBatch $submit): RedirectResponse
    {
        $membership = $this->ownSchoolClub($request);

        if (! $membership->hasCurrentTerm()) {
            return redirect()->route('member.school-club.students.index')
                ->withErrors(['students_file' => 'Your School Club membership is not currently active. Renew it before adding students.']);
        }

        $batch = $submit->handle($membership, $request->user(), $request->students(), $request->principalList());

        return redirect()->route('member.school-club.students.index')
            ->with('status', "Your list of {$batch->students_count} student(s) has been submitted for review. Student accounts are created once Aviation Club approves it.");
    }

    public function resubmit(SubmitStudentBatchRequest $request, SchoolClubStudentBatch $batch, SubmitStudentBatch $submit): RedirectResponse
    {
        $membership = $this->ownSchoolClub($request);

        abort_unless($batch->membership_id === $membership->id, 404);

        if ($batch->status !== SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED) {
            return redirect()->route('member.school-club.students.index')
                ->withErrors(['students_file' => 'This student list is not waiting for more details.']);
        }

        try {
            $submit->handle($membership, $request->user(), $request->students(), $request->principalList(), $batch);
        } catch (RuntimeException $exception) {
            return redirect()->route('member.school-club.students.index')->withErrors(['students_file' => $exception->getMessage()]);
        }

        return redirect()->route('member.school-club.students.index')
            ->with('status', 'Your corrected student list has been resubmitted for review.');
    }

    private function ownSchoolClub(Request $request): Membership
    {
        $membership = Membership::query()
            ->where('user_id', $request->user()->id)
            ->with(['category', 'terms'])
            ->first();

        abort_unless($membership !== null && $membership->isSchoolClubMain(), 403);

        return $membership;
    }
}
