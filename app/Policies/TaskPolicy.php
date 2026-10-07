<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewTasks);
    }

    public function view(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ViewTasks);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTasks);
    }

    public function update(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ManageTasks) && $task->isOpen();
    }

    public function complete(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ManageTasks) && $task->isOpen();
    }

    public function cancel(User $user, Task $task): bool
    {
        return $user->hasPermission(Permission::ManageTasks) && $task->isOpen();
    }
}
