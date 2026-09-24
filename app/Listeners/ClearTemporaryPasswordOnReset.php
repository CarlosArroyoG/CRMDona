<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Recuperación por correo (Filament): la persona eligió una contraseña nueva
 * con el enlace, así que ya no aplica la temporal del Administrador
 * (ADR-010). El cambio queda en la bitácora del usuario.
 */
class ClearTemporaryPasswordOnReset
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;
        if ($user instanceof User && $user->mustChangePassword()) {
            $user->forceFill(['password_change_required_at' => null])->save();
        }
    }
}
