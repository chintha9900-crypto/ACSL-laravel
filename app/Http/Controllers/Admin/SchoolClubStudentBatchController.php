<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Membership\DeliverAccountSetupLink;
use App\Actions\Membership\DeliverMemberNotification;
use App\Actions\Membership\DeliverWelcomeEmail;
use App\Actions\SchoolClub\ReviewStudentBatch;
use App\Exceptions\StudentBatchCannotBeReviewedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewStudentBatchRequest;
use App\Models\SchoolClubStudentBatch;
use App\Notifications\Membership\StudentBatchReviewed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SchoolClubStudentBatchController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SchoolClubStudentBatch::class);

        $status = $request->query('status');
        $status = array_key_exists((string) $status, SchoolClubStudentBatch::STATUS_LABELS) ? $status : null;

        return view('admin.school-club-batches.index', [
            'batches' => SchoolClubStudentBatch::query()
                ->with('membership.application:id,company_name')
                ->when($status, fn ($query) => $query->where('status', $status))
                ->latest('submitted_at')
                ->paginate(25)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(SchoolClubStudentBatch $batch): View
    {
        Gate::authorize('view', $batch);

        $batch->load(['membership.application:id,company_name', 'entries.membership:id,membership_number', 'studentList', 'submittedBy:id,name,email']);

        return view('admin.school-club-batches.show', ['batch' => $batch]);
    }

    /**
     * Record the decision, then (only after it has committed) email each newly
     * activated student their welcome and one-time setup link, and tell the
     * representative. A mail problem never affects the recorded decision.
     */
    public function review(
        ReviewStudentBatchRequest $request,
        SchoolClubStudentBatch $batch,
        ReviewStudentBatch $review,
        DeliverAccountSetupLink $deliverSetupLink,
        DeliverWelcomeEmail $deliverWelcome,
        DeliverMemberNotification $deliverNotification,
    ): RedirectResponse {
        $decision = $request->validated('decision');

        try {
            $activated = $review->handle($batch, $request->user(), $decision, $request->validated('note'), $request->validated('request_message'));
        } catch (StudentBatchCannotBeReviewedException $exception) {
            return redirect()->route('admin.school-club-batches.show', $batch)->withErrors(['decision' => $exception->getMessage()]);
        }

        $failedEmails = 0;
        foreach ($activated as $student) {
            $deliverWelcome->handle($student['membership']->load(['category', 'terms']));
            $failedEmails += $deliverSetupLink->handle($student['user'], $student['token']) ? 0 : 1;
        }

        $batch->refresh();
        if ($batch->submittedBy !== null) {
            $deliverNotification->handle($batch->submittedBy, new StudentBatchReviewed($batch));
        }

        $redirect = redirect()->route('admin.school-club-batches.show', $batch);

        if ($failedEmails > 0) {
            return $redirect->with('warning', "Decision recorded and the student memberships were created, but {$failedEmails} student setup email(s) could not be sent. Check the mail log; those students cannot set a password until they receive a link.");
        }

        return $redirect->with('status', match ($decision) {
            SchoolClubStudentBatch::STATUS_APPROVED => count($activated).' student membership(s) activated. Each student has been sent their own setup link.',
            SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED => 'More details requested from the school.',
            default => 'Student batch declined.',
        });
    }
}
