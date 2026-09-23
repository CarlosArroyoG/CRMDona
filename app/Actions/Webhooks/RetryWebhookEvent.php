<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\Permission;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vuelve a encolar una notificación fallida (por ejemplo, después de que el
 * proveedor volvió a estar disponible). Seguro de repetir: el procesamiento
 * consulta el estado actual y es idempotente.
 */
class RetryWebhookEvent
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(WebhookEvent $event, User $actor): WebhookEvent
    {
        if (! $actor->hasPermission(Permission::ViewWebhooks)) {
            throw new AuthorizationException('No tienes permiso para reprocesar notificaciones.');
        }

        $updated = DB::table('webhook_events')->where('id', $event->id)
            ->where('status', WebhookEventStatus::Failed->value)
            ->update(['status' => WebhookEventStatus::Pending->value, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['event' => 'Solo se reprocesan notificaciones fallidas.']);
        }

        ProcessWebhookEvent::dispatch($event->id);

        return $event->refresh();
    }
}
