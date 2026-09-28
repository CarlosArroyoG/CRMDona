<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Import;
use App\Models\User;

/**
 * Las filas rechazadas de una carga CSV solo las descarga quien la hizo,
 * mientras su usuario siga activo y sin contraseña temporal pendiente (la
 * ruta de descarga está fuera del panel y de su middleware).
 */
class ImportPolicy
{
    public function view(User $user, Import $import): bool
    {
        return $user->isActive() && ! $user->mustChangePassword() && $import->user_id === $user->id;
    }
}
