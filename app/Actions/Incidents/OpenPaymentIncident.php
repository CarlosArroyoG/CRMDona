<?php

declare(strict_types=1);

namespace App\Actions\Incidents;

use App\Enums\FailureCategory;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Jobs\SendPaymentIncidentAlert;
use App\Models\FiscalIncident;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentIncident;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\WebhookEvent;

/**
 * Abre una incidencia una sola vez por hecho (`dedupe_key` único). Si el
 * hecho ya tenía incidencia (aunque esté resuelta) la devuelve sin crear otra
 * ni volver a alertar. La alerta se envía después del commit.
 */
class OpenPaymentIncident
{
    public function handle(
        IncidentType $type,
        string $dedupeKey,
        ?Payment $payment = null,
        ?PaymentAttempt $attempt = null,
        ?Subscription $subscription = null,
        ?Refund $refund = null,
        ?PaymentDispute $dispute = null,
        ?WebhookEvent $webhookEvent = null,
        ?PaymentProvider $provider = null,
        ?FailureCategory $failureCategory = null,
    ): PaymentIncident {
        $incident = PaymentIncident::query()->createOrFirst(['dedupe_key' => $dedupeKey], [
            'type' => $type,
            'severity' => $type->severity(),
            'failure_category' => $failureCategory ?? $attempt?->failure_category,
            'provider' => $provider ?? $payment->provider ?? $subscription->provider ?? $refund->provider
                ?? $dispute->provider ?? $webhookEvent?->provider,
            'payment_id' => $payment->id ?? $attempt->payment_id ?? $refund->payment_id ?? $dispute?->payment_id,
            'payment_attempt_id' => $attempt?->id,
            'subscription_id' => $subscription->id ?? $payment?->subscription_id,
            'refund_id' => $refund?->id,
            'dispute_id' => $dispute?->id,
            'webhook_event_id' => $webhookEvent?->id,
            'status' => IncidentStatus::New,
            'detected_at' => now(),
        ]);

        if ($incident->wasRecentlyCreated) {
            SendPaymentIncidentAlert::dispatch($incident->id)->afterCommit();
        }

        $donation = ($payment !== null ? $payment->donation : null)
            ?? ($refund !== null ? $refund->payment->donation : null)
            ?? ($dispute !== null ? $dispute->payment->donation : null);
        if ($donation?->activeCfdi() !== null && $dispute !== null) {
            FiscalIncident::query()->firstOrCreate(
                ['dedupe_key' => "dispute:{$dispute->id}:cfdi_review"],
                [
                    'donation_id' => $donation->id,
                    'type' => 'chargeback_cfdi_review',
                    'status' => 'open',
                    'details' => 'Existe una disputa o contracargo relacionado con un CFDI. Requiere resolución humana; el CRM no cancela ni sustituye automáticamente.',
                    'detected_at' => now(),
                ],
            );
        }

        return $incident;
    }
}
