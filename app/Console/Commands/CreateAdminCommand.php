<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Users\CreateAdministrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-admin')]
#[Description('Crea un usuario Administrador capturando la contraseña de forma oculta')]
class CreateAdminCommand extends Command
{
    public const string DEFAULT_EMAIL = 'licarroyogarfias@gmail.com';

    public function handle(CreateAdministrator $createAdministrator): int
    {
        $name = text(label: 'Nombre', required: true);
        $email = text(label: 'Correo electrónico', default: self::DEFAULT_EMAIL, required: true);
        $password = password(label: 'Contraseña', required: true, hint: 'Mínimo '.CreateAdministrator::PASSWORD_MIN_LENGTH.' caracteres, con letras y números.');
        $passwordConfirmation = password(label: 'Confirma la contraseña', required: true);

        try {
            $user = $createAdministrator->handle($name, $email, $password, $passwordConfirmation);
        } catch (ValidationException $exception) {
            $this->components->error('No se creó el administrador.');

            foreach ($exception->validator->errors()->all() as $message) {
                $this->line("  • {$message}");
            }

            return self::FAILURE;
        }

        $this->components->info("Administrador creado: {$user->email}. Ya puede iniciar sesión en /admin.");

        return self::SUCCESS;
    }
}
