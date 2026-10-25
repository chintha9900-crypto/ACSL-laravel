<?php

namespace App\Policies;

use App\Models\PaymentReview;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * The manual payment-review queue — the same permission as payment review.
 */
class PaymentReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ReviewPayments);
    }

    public function update(User $user, PaymentReview $review): bool
    {
        return $user->hasPermission(Ability::ReviewPayments);
    }
}
