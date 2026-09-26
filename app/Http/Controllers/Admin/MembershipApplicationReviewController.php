<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Membership\ReviewMembershipApplication;
use App\Exceptions\MembershipApplicationCannotBeReviewedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewMembershipApplicationRequest;
use App\Models\MembershipApplication;
use Illuminate\Http\RedirectResponse;

class MembershipApplicationReviewController extends Controller
{
    /**
     * Record the admin's decision on a submitted application.
     */
    public function store(
        ReviewMembershipApplicationRequest $request,
        MembershipApplication $application,
        ReviewMembershipApplication $review,
    ): RedirectResponse {
        $decision = $request->validated('decision');

        try {
            $review->handle($application, $request->user(), $decision, $request->validated('note'), $request->validated('request_message'));
        } catch (MembershipApplicationCannotBeReviewedException $exception) {
            return redirect()
                ->route('admin.membership-applications.show', $application)
                ->withErrors(['decision' => $exception->getMessage()]);
        }

        return redirect()
            ->route('admin.membership-applications.show', $application)
            ->with('status', 'Decision recorded: '.MembershipApplication::labelFor($decision).'.');
    }
}
