<?php

declare(strict_types=1);

namespace App\Actions\Webhooks;

use App\Enums\PaymentProvider;
use App\Enums\WebhookEventStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Payments\Data\InboundWebhook;

/**
 * Guarda una notificación ya autenticada, una sola vez por identificador de
 * evento del proveedor (INSERT … ON CONFLICT DO NOTHING), y encola su
 * procesamiento solo si es nueva. Es rápido: el trabajo pesado ocurre en el
 * Job, fuera de la petición HTTP.
 */
class RecordWebhookEvent
{
    public function handle(PaymentProvider $provider, InboundWebhook $webhook): WebhookEvent
    {
        $status = $webhook->resourceType === null || $webhook->resourceExternalId === null
            ? WebhookEventStatus::Ignored
            : WebhookEventStatus::Pending;
        $now = now();

        $inserted = WebhookEvent::query()->insertOrIgnore([
            'provider' => $provider->value,
            'external_event_id' => mb_substr($webhook->externalEventId, 0, 255),
            'event_type' => mb_substr($webhook->eventType, 0, 100),
            'resource_type' => $webhook->resourceType,
            'resource_external_id' => $webhook->resourceExternalId !== null ? mb_substr($webhook->resourceExternalId, 0, 100) : null,
            'provider_created_at' => $webhook->providerCreatedAt,
            'payload' => json_encode($webhook->payload, JSON_THROW_ON_ERROR),
            'received_at' => $now,
            'status' => $status->value,
            'processed_at' => $status === WebhookEventStatus::Ignored ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $event = WebhookEvent::query()->where('provider', $provider->value)
            ->where('external_event_id', mb_substr($webhook->externalEventId, 0, 255))->firstOrFail();

        if ($inserted === 1 && $status === WebhookEventStatus::Pending) {
            ProcessWebhookEvent::dispatch($event->id);
        }

        return $event;
    }
}
