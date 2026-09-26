<?php

namespace App\Http\Controllers\Membership;

use App\Actions\Membership\NotifyApplicant;
use App\Actions\Membership\SubmitMembershipApplication;
use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\StoreMembershipApplicationRequest;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Notifications\Membership\ApplicationSubmitted;
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
            'mobileCountries' => $this->mobileCountries(),
        ]);
    }

    /**
     * Submit an application. No user account is created or required.
     */
    public function store(StoreMembershipApplicationRequest $request, SubmitMembershipApplication $submit, NotifyApplicant $notify): RedirectResponse
    {
        $application = $submit->handle($request->applicationData(), $request->proofDocuments());

        // M1: never allowed to affect the submission itself — see NotifyApplicant.
        $notify->handle($application, new ApplicationSubmitted($application));

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

    /**
     * Display data only, for the mobile field's country-code selector. The
     * `mobile` column/validation/storage are unaffected — the applicant's
     * browser combines a chosen entry here with the typed number into the
     * one existing `mobile` value before it is ever submitted.
     *
     * @return list<array{code: string, flag: string, name: string}>
     */
    private function mobileCountries(): array
    {
        return [
            ['code' => '+94', 'flag' => '🇱🇰', 'name' => 'Sri Lanka'],
            ['code' => '+91', 'flag' => '🇮🇳', 'name' => 'India'],
            ['code' => '+92', 'flag' => '🇵🇰', 'name' => 'Pakistan'],
            ['code' => '+880', 'flag' => '🇧🇩', 'name' => 'Bangladesh'],
            ['code' => '+977', 'flag' => '🇳🇵', 'name' => 'Nepal'],
            ['code' => '+960', 'flag' => '🇲🇻', 'name' => 'Maldives'],
            ['code' => '+971', 'flag' => '🇦🇪', 'name' => 'United Arab Emirates'],
            ['code' => '+966', 'flag' => '🇸🇦', 'name' => 'Saudi Arabia'],
            ['code' => '+974', 'flag' => '🇶🇦', 'name' => 'Qatar'],
            ['code' => '+965', 'flag' => '🇰🇼', 'name' => 'Kuwait'],
            ['code' => '+973', 'flag' => '🇧🇭', 'name' => 'Bahrain'],
            ['code' => '+968', 'flag' => '🇴🇲', 'name' => 'Oman'],
            ['code' => '+962', 'flag' => '🇯🇴', 'name' => 'Jordan'],
            ['code' => '+90', 'flag' => '🇹🇷', 'name' => 'Turkey'],
            ['code' => '+44', 'flag' => '🇬🇧', 'name' => 'United Kingdom'],
            ['code' => '+353', 'flag' => '🇮🇪', 'name' => 'Ireland'],
            ['code' => '+33', 'flag' => '🇫🇷', 'name' => 'France'],
            ['code' => '+49', 'flag' => '🇩🇪', 'name' => 'Germany'],
            ['code' => '+34', 'flag' => '🇪🇸', 'name' => 'Spain'],
            ['code' => '+39', 'flag' => '🇮🇹', 'name' => 'Italy'],
            ['code' => '+351', 'flag' => '🇵🇹', 'name' => 'Portugal'],
            ['code' => '+31', 'flag' => '🇳🇱', 'name' => 'Netherlands'],
            ['code' => '+32', 'flag' => '🇧🇪', 'name' => 'Belgium'],
            ['code' => '+41', 'flag' => '🇨🇭', 'name' => 'Switzerland'],
            ['code' => '+43', 'flag' => '🇦🇹', 'name' => 'Austria'],
            ['code' => '+46', 'flag' => '🇸🇪', 'name' => 'Sweden'],
            ['code' => '+47', 'flag' => '🇳🇴', 'name' => 'Norway'],
            ['code' => '+45', 'flag' => '🇩🇰', 'name' => 'Denmark'],
            ['code' => '+358', 'flag' => '🇫🇮', 'name' => 'Finland'],
            ['code' => '+48', 'flag' => '🇵🇱', 'name' => 'Poland'],
            ['code' => '+30', 'flag' => '🇬🇷', 'name' => 'Greece'],
            ['code' => '+7', 'flag' => '🇷🇺', 'name' => 'Russia'],
            ['code' => '+380', 'flag' => '🇺🇦', 'name' => 'Ukraine'],
            ['code' => '+1', 'flag' => '🇺🇸', 'name' => 'United States'],
            ['code' => '+1', 'flag' => '🇨🇦', 'name' => 'Canada'],
            ['code' => '+61', 'flag' => '🇦🇺', 'name' => 'Australia'],
            ['code' => '+64', 'flag' => '🇳🇿', 'name' => 'New Zealand'],
            ['code' => '+86', 'flag' => '🇨🇳', 'name' => 'China'],
            ['code' => '+81', 'flag' => '🇯🇵', 'name' => 'Japan'],
            ['code' => '+82', 'flag' => '🇰🇷', 'name' => 'South Korea'],
            ['code' => '+852', 'flag' => '🇭🇰', 'name' => 'Hong Kong'],
            ['code' => '+65', 'flag' => '🇸🇬', 'name' => 'Singapore'],
            ['code' => '+60', 'flag' => '🇲🇾', 'name' => 'Malaysia'],
            ['code' => '+66', 'flag' => '🇹🇭', 'name' => 'Thailand'],
            ['code' => '+62', 'flag' => '🇮🇩', 'name' => 'Indonesia'],
            ['code' => '+63', 'flag' => '🇵🇭', 'name' => 'Philippines'],
            ['code' => '+84', 'flag' => '🇻🇳', 'name' => 'Vietnam'],
            ['code' => '+886', 'flag' => '🇹🇼', 'name' => 'Taiwan'],
            ['code' => '+27', 'flag' => '🇿🇦', 'name' => 'South Africa'],
            ['code' => '+20', 'flag' => '🇪🇬', 'name' => 'Egypt'],
            ['code' => '+234', 'flag' => '🇳🇬', 'name' => 'Nigeria'],
            ['code' => '+254', 'flag' => '🇰🇪', 'name' => 'Kenya'],
            ['code' => '+233', 'flag' => '🇬🇭', 'name' => 'Ghana'],
            ['code' => '+212', 'flag' => '🇲🇦', 'name' => 'Morocco'],
            ['code' => '+55', 'flag' => '🇧🇷', 'name' => 'Brazil'],
            ['code' => '+52', 'flag' => '🇲🇽', 'name' => 'Mexico'],
            ['code' => '+54', 'flag' => '🇦🇷', 'name' => 'Argentina'],
        ];
    }
}
