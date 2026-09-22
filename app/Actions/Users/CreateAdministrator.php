<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Crea un usuario con rol Administrador. Nunca modifica usuarios existentes:
 * si el correo ya está registrado, falla.
 */
class CreateAdministrator
{
    public const int PASSWORD_MIN_LENGTH = 12;

    /**
     * @throws ValidationException
     */
    public function handle(string $name, string $email, string $password, string $passwordConfirmation): User
    {
        $data = Validator::make(
            [
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email:rfc,strict', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'confirmed', Password::min(self::PASSWORD_MIN_LENGTH)->letters()->numbers()],
            ],
            [
                'email.unique' => 'Ese correo ya pertenece a un usuario. No se modificó ningún usuario existente.',
            ],
        )->validate();

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->forceFill([
            'role' => Role::Administrator,
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
