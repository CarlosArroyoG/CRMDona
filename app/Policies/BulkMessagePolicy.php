<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BulkMessage;
use App\Models\User;

/**
 * Envíos masivos: los consulta quien ve el historial de comunicaciones; los
 * preparan, envían y detienen Administrador y Coordinador. Solo un borrador
 * se edita o se elimina.
 */
class BulkMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewCommunications);
    }

    public function view(User $user, BulkMessage $message): bool
    {
        return $user->hasPermission(Permission::ViewCommunications);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::SendBulkMessages);
    }

    public function update(User $user, BulkMessage $message): bool
    {
        return $message->isDraft() && $user->hasPermission(Permission::SendBulkMessages);
    }

    public function send(User $user, BulkMessage $message): bool
    {
        return $message->isDraft() && $user->hasPermission(Permission::SendBulkMessages);
    }

    public function stop(User $user, BulkMessage $message): bool
    {
        return $message->isStoppable() && $user->hasPermission(Permission::SendBulkMessages);
    }

    public function delete(User $user, BulkMessage $message): bool
    {
        return $message->isDraft() && $user->hasPermission(Permission::SendBulkMessages);
    }
}
