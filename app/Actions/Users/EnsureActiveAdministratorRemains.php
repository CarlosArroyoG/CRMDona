<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Impide quedarse sin ningún Administrador activo (nadie podría gestionar
 * usuarios). Debe llamarse dentro de la transacción del cambio.
 */
class EnsureActiveAdministratorRemains
{
    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $field): void
    {
        if ($user->role !== Role::Administrator || ! $user->isActive()) {
            return;
        }

        $othersExist = User::query()
            ->where('role', Role::Administrator->value)
            ->whereNull('deactivated_at')
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->exists();

        if (! $othersExist) {
            throw ValidationException::withMessages([
                $field => 'Debe quedar al menos un Administrador activo.',
            ]);
        }
    }
}
