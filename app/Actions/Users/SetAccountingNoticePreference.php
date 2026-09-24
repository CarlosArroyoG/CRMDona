<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * El Administrador elige quién recibe los avisos a Contabilidad. Llevan datos
 * fiscales, así que solo se activan en roles con `accounting.process`
 * (Administrador y Contador).
 */
class SetAccountingNoticePreference
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $user, bool $enabled, User $actor): User
    {
        if (! $actor->hasPermission(Permission::ManageUsers)) {
            throw new AuthorizationException('Solo el Administrador cambia quién recibe los avisos a Contabilidad.');
        }

        if ($enabled && ! ($user->role !== null && Permission::ProcessAccounting->allows($user->role))) {
            throw ValidationException::withMessages(['receives_accounting_notices' => 'Solo un Administrador o un Contador puede recibir los avisos a Contabilidad.']);
        }

        if ($user->receives_accounting_notices !== $enabled) {
            $user->forceFill(['receives_accounting_notices' => $enabled])->save();
        }

        return $user;
    }
}
