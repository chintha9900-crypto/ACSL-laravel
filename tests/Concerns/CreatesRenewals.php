<?php

namespace Tests\Concerns;

use App\Actions\Membership\ActivateMembership;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\PaymentBankAccount;

/**
 * An activated membership plus the renewal configuration (plan, bank account)
 * that only ACI would normally supply (OD-01, OD-02) — test fixture values
 * only, never asserted as real business decisions.
 */
trait CreatesRenewals
{
    protected function activatedMembership(array $applicationAttributes = [], string $categoryCode = 'P'): Membership
    {
        $application = $this->application('approved', $applicationAttributes, $categoryCode);
        $membership = app(ActivateMembership::class)->handle($application, $this->admin())->membership;
        $membership->user->forceFill(['status' => 'active'])->save();

        return $membership->fresh(['category', 'terms', 'user']);
    }

    /**
     * At most one active plan per category is allowed (docs/database/04 §4), so
     * this is find-or-create per category: calling it again for the same
     * category (e.g. a second test membership in the same category) reuses the
     * existing plan instead of colliding with it.
     */
    protected function activePlan(Membership $membership, array $overrides = []): MembershipPlan
    {
        if ($overrides === []) {
            $existing = MembershipPlan::query()
                ->where('membership_category_id', $membership->membership_category_id)
                ->where('is_active', true)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return MembershipPlan::forceCreate(array_merge([
            'membership_category_id' => $membership->membership_category_id,
            'name' => 'Standard renewal',
            'fee_amount' => 100,
            'currency' => 'USD',
            'duration_months' => 12,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * At most one active account per currency is allowed (docs/database/06 §2);
     * find-or-create per currency for the same reason as `activePlan()`.
     */
    protected function activeBankAccount(string $currency = 'USD', array $overrides = []): PaymentBankAccount
    {
        if ($overrides === []) {
            $existing = PaymentBankAccount::query()->where('currency', $currency)->where('is_active', true)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return PaymentBankAccount::forceCreate(array_merge([
            'label' => 'Membership fees — '.$currency,
            'bank_name' => 'Test Bank',
            'account_name' => 'Aviation Club International',
            'account_number' => '1234567890',
            'currency' => $currency,
            'is_active' => true,
        ], $overrides));
    }

    /**
     * A membership with an active plan and bank account configured, ready to renew.
     */
    protected function renewableMembership(array $applicationAttributes = [], string $categoryCode = 'P'): Membership
    {
        $membership = $this->activatedMembership($applicationAttributes, $categoryCode);
        $this->activePlan($membership);
        $this->activeBankAccount('USD');

        return $membership;
    }
}
