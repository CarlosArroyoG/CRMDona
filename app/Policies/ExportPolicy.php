<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

/**
 * Un archivo exportado solo lo descarga quien lo generó, mientras su usuario
 * siga activo y no tenga una contraseña temporal pendiente de cambiar (la
 * ruta de descarga está fuera del panel y de su middleware).
 */
class ExportPolicy
{
    public function view(User $user, Export $export): bool
    {
        return $user->isActive() && ! $user->mustChangePassword() && $export->user_id === $user->id;
    }
}
