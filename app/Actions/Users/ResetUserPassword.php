<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Restablece la contraseña de un usuario dejando una contraseña TEMPORAL:
 * - se guarda solo su hash;
 * - obliga a cambiarla en el siguiente acceso (`password_change_required_at`);
 * - vence tras `auth.temporary_password_ttl_hours`;
 * - cambiar el hash cierra las demás sesiones (AuthenticateSession) y se
 *   renueva el token "recordarme";
 * - no cambia rol ni estado: un usuario desactivado sigue sin acceso.
 * La bitácora registra el evento, nunca la contraseña ni el hash.
 */
class ResetUserPassword
{
    public const int TEMPORARY_PASSWORD_LENGTH = 16;

    public const string SELF_RESET = 'No puedes restablecer tu propia contraseña: usa "Cambiar contraseña".';

    public const string NOT_ALLOWED = 'Solo un Administrador puede restablecer contraseñas.';

    /**
     * Desde el panel: genera la contraseña temporal y la devuelve UNA sola vez
     * para mostrarla al Administrador. Nunca se guarda en texto plano.
     *
     * @throws ValidationException
     */
    public function generateTemporary(User $target, User $actor): string
    {
        if (! $actor->hasPermission(Permission::ManageUsers)) {
            throw ValidationException::withMessages(['user' => self::NOT_ALLOWED]);
        }

        if ($target->is($actor)) {
            throw ValidationException::withMessages(['user' => self::SELF_RESET]);
        }

        $temporary = Str::password(self::TEMPORARY_PASSWORD_LENGTH, letters: true, numbers: true, symbols: false);
        $this->apply($target, $temporary);

        return $temporary;
    }

    /**
     * Desde la consola del servidor (`app:reset-user-password`), para
     * recuperar el acceso cuando ningún Administrador puede hacerlo desde el
     * panel. También queda como temporal: quien opera el servidor no debe
     * conocer la contraseña definitiva del usuario.
     *
     * @throws ValidationException
     */
    public function setFromConsole(
        User $target,
        #[SensitiveParameter] string $password,
        #[SensitiveParameter] string $passwordConfirmation,
    ): void {
        Validator::make(
            ['password' => $password, 'password_confirmation' => $passwordConfirmation],
            ['password' => ['required', 'string', 'confirmed', Password::defaults()]],
        )->validate();

        $this->apply($target, $password);
    }

    private function apply(User $target, #[SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($target, $password): void {
            $target->auditAs(AuditEvent::PasswordReset)->forceFill([
                'password' => $password,
                'password_change_required_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();
        });
    }
}
