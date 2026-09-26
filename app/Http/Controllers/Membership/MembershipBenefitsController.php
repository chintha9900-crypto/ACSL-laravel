<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Models\MembershipCategory;
use App\Models\MembershipPlan;
use App\Models\MembershipSetting;
use Illuminate\View\View;

class MembershipBenefitsController extends Controller
{
    /**
     * Public "Membership Benefits" page. Category names/descriptions and
     * renewal fees are read live from the database — never hard-coded — so
     * the page can never show a price ACI hasn't actually configured. A
     * category with no active plan yet simply shows no fee (the introductory
     * term is free regardless), matching how the apply page already handles
     * an empty category list.
     */
    public function show(): View
    {
        $categories = MembershipCategory::query()->acceptingApplications()->get();

        $plansByCategory = MembershipPlan::query()
            ->whereIn('membership_category_id', $categories->pluck('id'))
            ->where('is_active', true)
            ->get()
            ->keyBy('membership_category_id');

        return view('membership.benefits', [
            'categories' => $categories,
            'plansByCategory' => $plansByCategory,
            'introductoryMonths' => (int) MembershipSetting::current()->introductory_period_months,
        ]);
    }
}
