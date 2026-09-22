<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Solo el Administrador gestiona usuarios. Los usuarios no se eliminan: se
 * desactivan (conservan su historial de registros).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageUsers);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::ManageUsers);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageUsers);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::ManageUsers);
    }

    public function deactivate(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::ManageUsers) && ! $user->is($model) && $model->isActive();
    }

    public function reactivate(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::ManageUsers) && ! $model->isActive();
    }
}
