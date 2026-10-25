<?php

namespace App\Policies;

use App\Models\PaymentBankAccount;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Receiving bank accounts decide where customers send money, so only users
 * who manage settings (administrators) may change them.
 */
class PaymentBankAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageSettings);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageSettings);
    }

    public function update(User $user, PaymentBankAccount $account): bool
    {
        return $user->hasPermission(Ability::ManageSettings);
    }
}
