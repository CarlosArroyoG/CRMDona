<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WebhookEventStatus;
use App\Models\User;
use App\Models\WebhookEvent;

/**
 * Bandeja técnica de notificaciones: solo el Administrador.
 */
class WebhookEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewWebhooks);
    }

    public function view(User $user, WebhookEvent $event): bool
    {
        return $user->hasPermission(Permission::ViewWebhooks);
    }

    public function retry(User $user, WebhookEvent $event): bool
    {
        return $user->hasPermission(Permission::ViewWebhooks) && $event->status === WebhookEventStatus::Failed;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WebhookEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, WebhookEvent $event): bool
    {
        return false;
    }
}
