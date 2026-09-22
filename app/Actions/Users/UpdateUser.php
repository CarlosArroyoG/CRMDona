<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Cambia nombre, correo y rol. Nunca toca la contraseña.
 */
class UpdateUser
{
    public function __construct(private readonly EnsureActiveAdministratorRemains $guard) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, string $name, string $email, Role|string|null $role): User
    {
        $data = Validator::make(
            [
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'role' => $role instanceof Role ? $role->value : $role,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email:rfc,strict', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
                'role' => ['required', new Enum(Role::class)],
            ],
            ['email.unique' => 'Ese correo ya pertenece a otro usuario.'],
        )->validate();

        $newRole = Role::from($data['role']);

        return DB::transaction(function () use ($user, $data, $newRole): User {
            if ($newRole !== Role::Administrator) {
                $this->guard->handle($user, 'role');
            }

            $user->fill(['name' => $data['name'], 'email' => $data['email']]);
            $user->forceFill(['role' => $newRole])->save();

            return $user;
        });
    }
}
