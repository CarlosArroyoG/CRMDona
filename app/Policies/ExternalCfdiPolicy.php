<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ExternalCfdi;
use App\Models\User;

/**
 * CFDI externos (antecedentes): consultar y descargar con `cfdi.view`
 * (Administrador, Coordinador, Contador); adjuntar, reemplazar y retirar con
 * `cfdi.manage` (Administrador y Contador). Solo lectura, nada. Nadie los
 * borra.
 */
class ExternalCfdiPolicy
{
    public function view(User $user, ExternalCfdi $record): bool
    {
        return $user->hasPermission(Permission::ViewCfdis);
    }

    public function download(User $user, ExternalCfdi $record): bool
    {
        return $user->hasPermission(Permission::ViewCfdis);
    }

    public function manage(User $user, ExternalCfdi $record): bool
    {
        return $user->hasPermission(Permission::ManageExternalCfdis) && $record->isActive();
    }

    public function delete(User $user, ExternalCfdi $record): bool
    {
        return false;
    }
}
