<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PaymentRequest;
use App\Models\User;

/**
 * Solicitudes de pago: las ven quienes ven pagos (payments.view); las
 * preparan, envían, cancelan y regeneran Administrador y Coordinador
 * (payments.request). Se crean desde "Crear donativo", nunca por separado.
 */
class PaymentRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewPayments);
    }

    public function view(User $user, PaymentRequest $request): bool
    {
        return $user->hasPermission(Permission::ViewPayments);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, PaymentRequest $request): bool
    {
        return false;
    }

    /**
     * Ver y usar el enlace (abrir, copiar, enviar, cancelar, regenerar).
     */
    public function manage(User $user, PaymentRequest $request): bool
    {
        return $user->hasPermission(Permission::RequestPayments);
    }
}
