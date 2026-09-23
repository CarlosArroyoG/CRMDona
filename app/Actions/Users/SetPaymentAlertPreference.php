<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * El Administrador decide qué Coordinadores y Contadores reciben alertas de
 * pagos. Los Administradores siempre las reciben y Solo lectura nunca, así
 * que para ellos la preferencia no tiene efecto (queda registrada igual).
 */
class SetPaymentAlertPreference
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, bool $enabled, User $actor): User
    {
        if (! $actor->hasPermission(Permission::ManageUsers)) {
            throw new AuthorizationException('Solo el Administrador cambia las preferencias de alertas.');
        }

        if ($user->receives_payment_alerts !== $enabled) {
            $user->forceFill(['receives_payment_alerts' => $enabled])->save();
        }

        return $user;
    }
}
