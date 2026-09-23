<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;

/**
 * Donativos mensuales: todos los roles los consultan; pausar, reanudar y
 * cancelar, solo Administrador y Coordinador (y solo si el proveedor lo
 * admite, lo que valida cada Action).
 */
class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewSubscriptions);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $user->hasPermission(Permission::ViewSubscriptions);
    }

    public function pause(User $user, Subscription $subscription): bool
    {
        return $user->hasPermission(Permission::ManageSubscriptions)
            && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true);
    }

    public function resume(User $user, Subscription $subscription): bool
    {
        return $user->hasPermission(Permission::ManageSubscriptions) && $subscription->status === SubscriptionStatus::Paused;
    }

    public function cancel(User $user, Subscription $subscription): bool
    {
        return $user->hasPermission(Permission::ManageSubscriptions) && ! $subscription->status->isFinal();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return false;
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return false;
    }
}
