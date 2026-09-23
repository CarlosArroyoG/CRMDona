<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Users\ResetUserPassword;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Recuperación de acceso desde el servidor cuando ningún Administrador puede
 * restablecer la contraseña desde el panel. No crea usuarios ni cambia rol o
 * estado. La contraseña queda como TEMPORAL: el usuario la cambia al entrar,
 * así quien opera el servidor no conoce la definitiva.
 */
#[Signature('app:reset-user-password')]
#[Description('Restablece la contraseña de un usuario existente (contraseña oculta, cambio obligatorio al entrar)')]
class ResetUserPasswordCommand extends Command
{
    public function handle(ResetUserPassword $resetUserPassword): int
    {
        $email = Str::lower(trim(text(label: 'Correo electrónico del usuario', required: true)));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error('No existe un usuario con ese correo. Este comando no crea usuarios.');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Usuario', $user->name);
        $this->components->twoColumnDetail('Rol', $user->role?->getLabel() ?? 'Sin rol');
        $this->components->twoColumnDetail('Estado', $user->isActive() ? 'Activo' : 'Desactivado');

        if (! confirm(label: '¿Restablecer la contraseña de este usuario?', default: false)) {
            $this->components->warn('Operación cancelada. No se modificó nada.');

            return self::FAILURE;
        }

        $newPassword = password(label: 'Nueva contraseña temporal', required: true, hint: 'Mínimo 12 caracteres, con letras y números.');
        $confirmation = password(label: 'Confirma la contraseña temporal', required: true);

        try {
            $resetUserPassword->setFromConsole($user, $newPassword, $confirmation);
        } catch (ValidationException $exception) {
            $this->components->error('No se restableció la contraseña.');
            foreach ($exception->validator->errors()->all() as $message) {
                $this->line("  • {$message}");
            }

            return self::FAILURE;
        }

        $hours = config()->integer('auth.temporary_password_ttl_hours');
        $this->components->info("Contraseña restablecida para {$user->email}. Deberá cambiarla al entrar; vence en {$hours} horas.");

        if (! $user->isActive()) {
            $this->components->warn('El usuario sigue desactivado: no podrá entrar hasta que un Administrador lo reactive.');
        }

        return self::SUCCESS;
    }
}
