<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Refund;
use App\Models\User;

/**
 * Detalle de reembolsos (motivo, quién, resultado): Administrador y
 * Contador. Los demás ven la situación de reembolso en el pago.
 */
class RefundPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::RequestRefunds);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $user->hasPermission(Permission::RequestRefunds);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Refund $refund): bool
    {
        return false;
    }

    public function delete(User $user, Refund $refund): bool
    {
        return false;
    }
}
