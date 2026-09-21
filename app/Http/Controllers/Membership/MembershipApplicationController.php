<?php

namespace App\Http\Controllers\Membership;

use App\Actions\Membership\SubmitMembershipApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\StoreMembershipApplicationRequest;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MembershipApplicationController extends Controller
{
    /**
     * How long the confirmation link shown after submitting stays valid.
     */
    private const RECEIPT_LINK_HOURS = 24;

    /**
     * Show the public application page: one form whose category options come
     * from the active `membership_categories` rows. `?category=student|professional|veteran`
     * only pre-selects one; the applicant can change it on the same page.
     */
    public function create(Request $request): View
    {
        $categories = MembershipCategory::query()->acceptingApplications()->get();

        return view('membership.apply', [
            'categories' => $categories,
            'selected' => $this->selectedCategory($categories, $request),
            'proof' => config('uploads.aviation_proof'),
        ]);
    }

    /**
     * Submit an application. No user account is created or required.
     */
    public function store(StoreMembershipApplicationRequest $request, SubmitMembershipApplication $submit): RedirectResponse
    {
        $application = $submit->handle($request->applicationData(), $request->proofDocuments());

        return redirect()->to(URL::temporarySignedRoute(
            'membership.apply.submitted',
            now()->addHours(self::RECEIPT_LINK_HOURS),
            ['application' => $application],
        ));
    }

    /**
     * Confirmation shown after submitting. Reachable only through the signed,
     * expiring link returned by `store()`; the numeric id is never exposed.
     */
    public function show(MembershipApplication $application): View
    {
        return view('membership.application-submitted', ['application' => $application]);
    }

    /**
     * @param  Collection<int, MembershipCategory>  $categories
     */
    private function selectedCategory(Collection $categories, Request $request): ?MembershipCategory
    {
        $requested = Str::lower((string) $request->query('category', $request->old('category', '')));
        $code = MembershipCategory::SLUG_CODES[$requested] ?? Str::upper($requested);

        return $categories->firstWhere('code', $code);
    }
}
