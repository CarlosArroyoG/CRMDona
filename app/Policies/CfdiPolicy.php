<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Models\Cfdi;
use App\Models\User;

/**
 * CFDI (permisos aprobados el 2026-09-23):
 * - Administrador y Contador: ver, descargar, emitir, reintentar, sustituir,
 *   descartar, cancelar y detalle técnico;
 * - Coordinador: ver y descargar XML/PDF;
 * - Solo lectura: sin acceso (contienen datos fiscales del donante).
 * Nadie los edita ni borra.
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

    public function viewTechnical(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::ViewCfdiTechnicalDetails);
    }

    public function download(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::ViewCfdis) && $cfdi->status->isStamped();
    }

    public function retry(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::IssueCfdis) && $cfdi->status->canRetry();
    }

    public function discard(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::IssueCfdis) && $cfdi->status === CfdiStatus::Rejected && $cfdi->uuid === null;
    }

    public function cancel(User $user, Cfdi $cfdi): bool
    {
        return $user->hasPermission(Permission::CancelCfdis) && $cfdi->status === CfdiStatus::Stamped;
    }

    public function substitute(User $user, Cfdi $cfdi): bool
    {
        return $this->cancel($user, $cfdi) && $user->hasPermission(Permission::IssueCfdis) && ! $cfdi->replacement_pending;
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
