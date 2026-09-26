<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\MembershipApplication;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ApplicationStatusController extends Controller
{
    /**
     * The applicant's status page. Reachable only through the signed, expiring link
     * from `MembershipApplication::statusUrl()`; no account is needed and the
     * numeric id is never used. Only the fields the view needs are read.
     */
    public function show(Request $request, MembershipApplication $application): Response
    {
        $application->load('category:id,name');

        $openRequest = $application->status === MembershipApplication::STATUS_MORE_DETAILS_REQUIRED
            ? $application->openDetailsRequest()
            : null;

        return response()
            ->view('membership.application-status', [
                'application' => $application,
                'openRequest' => $openRequest,
                // The response form is signed with the same expiry as the link in use, so
                // answering never extends how long the applicant's link stays valid.
                'respondUrl' => $openRequest
                    ? $application->signedUrl('applications.respond', Carbon::createFromTimestamp((int) $request->query('expires')))
                    : null,
                'proof' => config('uploads.aviation_proof'),
            ])
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}
