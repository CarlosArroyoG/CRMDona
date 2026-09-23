<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PaymentDispute;
use App\Models\User;

/**
 * Detalle de disputas: Administrador y Contador. El Coordinador trabaja con
 * la incidencia operativa.
 */
class PaymentDisputePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewDisputes);
    }

    public function view(User $user, PaymentDispute $dispute): bool
    {
        return $user->hasPermission(Permission::ViewDisputes);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentDispute $dispute): bool
    {
        return false;
    }

    public function delete(User $user, PaymentDispute $dispute): bool
    {
        return false;
    }
}
