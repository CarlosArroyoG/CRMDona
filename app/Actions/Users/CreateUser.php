<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Crea un usuario con un rol. Nunca modifica usuarios existentes: si el
 * correo ya está registrado, falla. La contraseña solo se guarda como hash.
 */
class CreateUser
{
    public const int PASSWORD_MIN_LENGTH = 12;

    public const string DUPLICATE_EMAIL_MESSAGE = 'Ese correo ya pertenece a un usuario. No se modificó ningún usuario existente.';

    /**
     * @throws ValidationException
     */
    public function handle(string $name, string $email, Role|string|null $role, string $password, string $passwordConfirmation): User
    {
        $data = Validator::make(
            [
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'role' => $role instanceof Role ? $role->value : $role,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique('users', 'email')],
                'role' => ['required', new Enum(Role::class)],
                'password' => ['required', 'string', 'confirmed', Password::defaults()],
            ],
            ['email.unique' => self::DUPLICATE_EMAIL_MESSAGE],
        )->validate();

        $user = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);
        $user->forceFill([
            'role' => Role::from($data['role']),
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }
}
