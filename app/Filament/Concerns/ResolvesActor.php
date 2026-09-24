<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\Permission;
use App\Models\User;

/**
 * Usuario de la sesión para las comprobaciones de permisos del panel. La
 * matriz sigue en App\Enums\Permission; aquí solo se evita repetir cómo se
 * obtiene al usuario.
 */
trait ResolvesActor
{
    protected static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected static function actorCan(Permission $permission): bool
    {
        return self::actor()?->hasPermission($permission) ?? false;
    }
}
