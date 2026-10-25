<?php

namespace Tests\Concerns;

use App\Models\MembershipTerm;
use App\Models\User;

/**
 * Users for members-only e-shop access, built through the real membership
 * activation workflow (CreatesRenewals::activatedMembership()). Requires the
 * using test to also use CreatesRenewals and CreatesReviewableApplications.
 */
trait CreatesEligibleMembers
{
    /**
     * An active account whose membership term is valid today.
     */
    protected function eligibleMember(): User
    {
        return $this->activatedMembership()->user;
    }

    /**
     * A member whose only term has expired (status `expired`, dates past).
     */
    protected function expiredMember(): User
    {
        $membership = $this->activatedMembership();
        $membership->terms()->update([
            'status' => MembershipTerm::STATUS_EXPIRED,
            'starts_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->subYear()->toDateString(),
        ]);

        return $membership->user->fresh();
    }

    /**
     * A member whose term still says `active` but whose dates have passed —
     * the daily expiry job has not run yet. Not eligible.
     */
    protected function lapsedButNotYetExpiredMember(): User
    {
        $membership = $this->activatedMembership();
        $membership->terms()->update([
            'starts_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->subDays(3)->toDateString(),
        ]);

        return $membership->user->fresh();
    }
}
