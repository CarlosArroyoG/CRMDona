<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Models\Cfdi;
use App\Models\User;

/**
 * CFDI: consultar y descargar (Administrador, Coordinador, Contador);
 * emitir, reintentar y cancelar (Administrador y Contador). Solo lectura no
 * ve CFDI (contienen datos fiscales del donante). Nadie los edita ni borra.
 */
class CfdiPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewCfdis);
    }

    public function view(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::ViewCfdis);
    }

    public function download(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::ViewCfdis) && $cfdi->status->isStamped();
    }

    public function retry(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::IssueCfdis) && $cfdi->status->canRetry();
    }

    public function cancel(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::CancelCfdis) && $cfdi->status === CfdiStatus::Stamped;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Cfdi $cfdi): bool
    {
        return false;
    }

    public function delete(User $user, Cfdi $cfdi): bool
    {
        return false;
    }
}
