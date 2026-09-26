<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * A payment is visible to the member it belongs to, or to an active admin.
 * Only an active admin may confirm or reject one.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->isActiveAdmin($user) || $payment->user_id === $user->id;
    }

    public function review(User $user, Payment $payment): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
