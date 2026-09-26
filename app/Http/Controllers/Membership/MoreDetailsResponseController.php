<?php

namespace App\Http\Controllers\Membership;

use App\Actions\Membership\RespondToMoreDetails;
use App\Exceptions\MoreDetailsNotRequestedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\RespondToMoreDetailsRequest;
use App\Models\MembershipApplication;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;

class MoreDetailsResponseController extends Controller
{
    /**
     * Record the applicant's answer to a "more details" request. The route is signed,
     * so only the holder of this application's own link can reach it; no account is
     * needed. A repeated submission finds no open request and is refused.
     */
    public function store(
        RespondToMoreDetailsRequest $request,
        MembershipApplication $application,
        RespondToMoreDetails $respond,
    ): RedirectResponse {
        $statusUrl = $application->signedUrl('applications.show', Carbon::createFromTimestamp((int) $request->query('expires')));

        try {
            $respond->handle($application, $request->validated('response_message'), $request->file('response_documents', []));
        } catch (MoreDetailsNotRequestedException $exception) {
            return redirect()->to($statusUrl)->withErrors(['response_message' => $exception->getMessage()]);
        }

        return redirect()->to($statusUrl)->with('status', 'Thank you. Your additional information has been received and will be reviewed by ACI.');
    }
}
