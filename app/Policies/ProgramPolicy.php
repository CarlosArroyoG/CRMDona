<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Program;
use App\Models\User;

class ProgramPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewPrograms);
    }

    public function view(User $user, Program $program): bool
    {
        return $user->hasPermission(Permission::ViewPrograms);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManagePrograms);
    }

    public function update(User $user, Program $program): bool
    {
        return $user->hasPermission(Permission::ManagePrograms);
    }

    public function delete(User $user, Program $program): bool
    {
        return $user->hasPermission(Permission::DeletePrograms)
            && ! $program->donations()->exists()
            && ! $program->campaigns()->exists();
    }

    public function export(User $user): bool
    {
        return $user->hasPermission(Permission::ExportPrograms);
    }
}
