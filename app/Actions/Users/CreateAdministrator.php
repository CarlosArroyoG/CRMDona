<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Crea un usuario con rol Administrador (comando `app:create-admin`).
 */
class CreateAdministrator
{
    public function __construct(private readonly CreateUser $createUser) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $name, string $email, string $password, string $passwordConfirmation): User
    {
        return $this->createUser->handle($name, $email, Role::Administrator, $password, $passwordConfirmation);
    }
}
