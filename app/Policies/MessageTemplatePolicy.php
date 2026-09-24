<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MessageTemplate;
use App\Models\User;

/**
 * Plantillas de correo: las editan Administrador y Coordinador. No se crean
 * ni se borran (una por tipo de correo).
 */
class MessageTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageMessageTemplates);
    }

    public function view(User $user, MessageTemplate $template): bool
    {
        return ! $template->kind->isHistorical() && $user->hasPermission(Permission::ManageMessageTemplates);
    }

    public function update(User $user, MessageTemplate $template): bool
    {
        return ! $template->kind->isHistorical() && $user->hasPermission(Permission::ManageMessageTemplates);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, MessageTemplate $template): bool
    {
        return false;
    }
}
