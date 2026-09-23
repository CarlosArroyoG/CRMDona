<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Actions\Payments\ApplyProviderSnapshot;
use App\Enums\AuditSource;
use App\Enums\IncidentType;
use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\Exceptions\PaymentReferenceNotFoundException;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Payments\GatewayRegistry;
use App\Support\AuditOrigin;
use App\Support\SensitiveData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Procesa una notificación de la bandeja (fase-2-diseno-pagos.md §12):
 * consulta el estado ACTUAL del recurso en el proveedor (sin transacción
 * abierta), y luego lo aplica con bloqueo. Por eso no importa el orden de
 * llegada ni si la notificación se repite. Ejecutarlo de nuevo sobre un
 * evento ya procesado no hace nada.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $webhookEventId)
    {
        $this->tries = config()->integer('payments.webhooks.tries');
        /** @var list<int> $backoff */
        $backoff = config()->array('payments.webhooks.backoff');
        $this->backoff = $backoff;
    }

    public function handle(GatewayRegistry $registry, ApplyProviderSnapshot $apply, AuditOrigin $origin): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null || in_array($event->status, [WebhookEventStatus::Processed, WebhookEventStatus::Ignored], true)
            || $event->resource_type === null || $event->resource_external_id === null) {
            return;
        }

        WebhookEvent::query()->whereKey($event->id)->increment('attempts');

        $snapshot = $registry->get($event->provider)->fetch($event->resource_type, $event->resource_external_id);
        $references = $origin->run(AuditSource::Webhook, fn (): array => $apply->handle($event->provider, $snapshot));
        $linked = array_filter($references, fn (?int $id): bool => $id !== null) !== [];

        $event->forceFill([
            ...$references,
            'status' => $linked ? WebhookEventStatus::Processed : WebhookEventStatus::Ignored,
            'processed_at' => now(),
            'last_error' => null,
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        $event = WebhookEvent::query()->find($this->webhookEventId);
        if ($event === null) {
            return;
        }

        $event->forceFill([
            'status' => WebhookEventStatus::Failed,
            'last_error' => SensitiveData::safeText(self::describe($exception)),
        ])->save();

        $incidents = app(OpenPaymentIncident::class);
        if ($exception instanceof ProviderUnavailableException) {
            $incidents->handle(
                IncidentType::ProviderUnavailable,
                "provider:{$event->provider->value}:unavailable:".now()->format('Y-m-d\TH'),
                webhookEvent: $event,
            );

            return;
        }

        $incidents->handle(IncidentType::WebhookUnprocessable, "webhook:{$event->id}:unprocessable", webhookEvent: $event);
    }

    /**
     * Solo los mensajes que escribimos nosotros llegan a la base de datos;
     * de cualquier otra excepción se guarda la clase.
     */
    private static function describe(Throwable $exception): string
    {
        return $exception instanceof PaymentProviderException || $exception instanceof PaymentReferenceNotFoundException
            ? $exception->getMessage()
            : 'Error interno ('.class_basename($exception).'). Revisa los logs del servidor.';
    }
}
