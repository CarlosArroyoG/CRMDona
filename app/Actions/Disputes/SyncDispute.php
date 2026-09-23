<?php

declare(strict_types=1);

namespace App\Actions\Disputes;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Payments\Data\DisputeSnapshot;
use App\Payments\Exceptions\PaymentReferenceNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Registra o actualiza una disputa (idempotente por identificador externo).
 * Una disputa nueva abre una incidencia crítica una sola vez. No modifica el
 * pago, el donativo, el recibo ni el CFDI.
 */
class SyncDispute
{
    public function __construct(private readonly OpenPaymentIncident $openIncident) {}

    /**
     * @throws PaymentReferenceNotFoundException si el pago todavía no existe en el CRM (el Job reintenta)
     */
    public function handle(PaymentProvider $provider, DisputeSnapshot $snapshot): PaymentDispute
    {
        return DB::transaction(function () use ($provider, $snapshot): PaymentDispute {
            $payment = $this->locatePayment($provider, $snapshot)
                ?? throw new PaymentReferenceNotFoundException('La disputa se refiere a un pago que el CRM todavía no conoce.');

            $dispute = PaymentDispute::query()->createOrFirst(
                ['provider' => $provider, 'external_id' => $snapshot->externalId],
                [
                    'payment_id' => $payment->id,
                    'amount' => $snapshot->amount,
                    'status' => $snapshot->status,
                    'provider_status' => mb_substr($snapshot->providerStatus, 0, 50),
                    'provider_reason' => $snapshot->providerReason !== null ? mb_substr($snapshot->providerReason, 0, 100) : null,
                    'opened_at' => $snapshot->openedAt ?? now(),
                    'evidence_due_at' => $snapshot->evidenceDueAt,
                    'closed_at' => $snapshot->closedAt,
                    'provider_updated_at' => $snapshot->providerUpdatedAt,
                ],
            );

            if ($dispute->wasRecentlyCreated) {
                $this->openIncident->handle(IncidentType::DisputeOpened, "dispute:{$dispute->id}:opened", payment: $payment, dispute: $dispute);

                return $dispute;
            }

            $dispute = PaymentDispute::query()->lockForUpdate()->findOrFail($dispute->id);
            $isStale = $snapshot->providerUpdatedAt !== null && $dispute->provider_updated_at !== null
                && $snapshot->providerUpdatedAt->lessThan($dispute->provider_updated_at);

            if ($isStale || $snapshot->status === $dispute->status) {
                return $dispute;
            }

            if (! $dispute->status->canTransitionTo($snapshot->status)) {
                $this->openIncident->handle(
                    IncidentType::StateInconsistency,
                    "dispute:{$dispute->id}:inconsistent:{$dispute->status->value}:{$snapshot->providerStatus}",
                    dispute: $dispute,
                );

                return $dispute;
            }

            $dispute->forceFill([
                'status' => $snapshot->status,
                'provider_status' => mb_substr($snapshot->providerStatus, 0, 50),
                'evidence_due_at' => $snapshot->evidenceDueAt ?? $dispute->evidence_due_at,
                'closed_at' => $snapshot->status->isOpen() ? null : ($snapshot->closedAt ?? now()),
                'provider_updated_at' => $snapshot->providerUpdatedAt ?? $dispute->provider_updated_at,
            ])->save();

            return $dispute;
        });
    }

    private function locatePayment(PaymentProvider $provider, DisputeSnapshot $snapshot): ?Payment
    {
        if ($snapshot->paymentExternalId !== null) {
            $payment = Payment::query()->where('provider', $provider->value)->where('external_id', $snapshot->paymentExternalId)->first();
            if ($payment !== null) {
                return $payment;
            }
        }

        if ($snapshot->attemptExternalId !== null) {
            $paymentId = PaymentAttempt::query()->where('provider', $provider->value)
                ->where('external_id', $snapshot->attemptExternalId)->value('payment_id');

            return is_int($paymentId) ? Payment::query()->find($paymentId) : null;
        }

        return null;
    }
}
