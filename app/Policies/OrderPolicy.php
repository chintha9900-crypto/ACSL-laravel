<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->isActiveAdmin($user);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->isActiveAdmin($user);
    }

    private function isActiveAdmin(User $user): bool
    {
        return $user->role === 'admin' && $user->status === 'active';
    }
}
