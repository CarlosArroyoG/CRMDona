<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class TagPolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageTags);
    }
}
