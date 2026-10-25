<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * A payment is visible to the member it belongs to, or to whoever holds the
 * `payments.review` permission (RBAC foundation) — `admin` and `editor`.
 * Only a holder of that permission may confirm or reject one.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ReviewPayments);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->hasPermission(Ability::ReviewPayments) || $payment->user_id === $user->id;
    }

    public function review(User $user, Payment $payment): bool
    {
        return $user->hasPermission(Ability::ReviewPayments);
    }

    /**
     * Recording a refund is admin-only. No permission grants it to other
     * roles; an active admin passes through the `Gate::before` bypass
     * (AppServiceProvider). Editors keep `review` for flagged payments and
     * bank-transfer approval.
     */
    public function recordRefund(User $user, Payment $payment): bool
    {
        return false;
    }
}
