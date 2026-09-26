<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Membership\NotifyApplicant;
use App\Actions\Membership\ReviewMembershipApplication;
use App\Exceptions\MembershipApplicationCannotBeReviewedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewMembershipApplicationRequest;
use App\Models\MembershipApplication;
use App\Notifications\Membership\ApplicationRejected;
use App\Notifications\Membership\ApprovedIntroductory;
use App\Notifications\Membership\MoreDetailsRequested;
use Illuminate\Http\RedirectResponse;
use Illuminate\Notifications\Notification;

class MembershipApplicationReviewController extends Controller
{
    /**
     * Record the admin's decision on a submitted application, then email the
     * applicant (M2/M3/M4) — never inside `ReviewMembershipApplication` itself,
     * so a mail problem can never affect the recorded decision.
     */
    public function store(
        ReviewMembershipApplicationRequest $request,
        MembershipApplication $application,
        ReviewMembershipApplication $review,
        NotifyApplicant $notify,
    ): RedirectResponse {
        $decision = $request->validated('decision');
        $requestMessage = $request->validated('request_message');

        try {
            $review->handle($application, $request->user(), $decision, $request->validated('note'), $requestMessage);
        } catch (MembershipApplicationCannotBeReviewedException $exception) {
            return redirect()
                ->route('admin.membership-applications.show', $application)
                ->withErrors(['decision' => $exception->getMessage()]);
        }

        $notification = match ($decision) {
            MembershipApplication::STATUS_MORE_DETAILS_REQUIRED => new MoreDetailsRequested($application, $requestMessage),
            MembershipApplication::STATUS_APPROVED => new ApprovedIntroductory($application),
            MembershipApplication::STATUS_REJECTED => new ApplicationRejected($application),
            default => null,
        };

        if ($notification instanceof Notification) {
            $notify->handle($application, $notification);
        }

        return redirect()
            ->route('admin.membership-applications.show', $application)
            ->with('status', 'Decision recorded: '.MembershipApplication::labelFor($decision).'.');
    }
}
