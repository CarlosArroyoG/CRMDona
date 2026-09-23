<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Models\Payment;
use App\Models\User;

/**
 * Pagos: todos los roles ven la información operativa; el detalle técnico,
 * solo Administrador y Contador. Nadie crea, edita ni elimina pagos a mano:
 * los escribe el proveedor a través de las Actions.
 */
class PaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewPayments);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->hasPermission(Permission::ViewPayments);
    }

    public function viewTechnical(User $user): bool
    {
        return $user->hasPermission(Permission::ViewPaymentTechnicalDetails);
    }

    public function export(User $user): bool
    {
        return $user->hasPermission(Permission::ExportPayments);
    }

    public function refund(User $user, Payment $payment): bool
    {
        return $user->hasPermission(Permission::RequestRefunds) && $payment->status === PaymentStatus::Succeeded;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        return false;
    }
}
