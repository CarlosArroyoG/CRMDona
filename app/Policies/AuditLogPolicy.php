<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;

/**
 * Bitácora de solo lectura, solo para el Administrador. Nadie la crea,
 * modifica ni elimina desde la interfaz (la base de datos también lo impide).
 */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewAuditLog);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->hasPermission(Permission::ViewAuditLog);
    }
}
