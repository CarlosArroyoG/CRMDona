<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Actions\Refunds\SyncRefund;
use App\Enums\IncidentType;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use App\Payments\Data\AttemptSnapshot;
use App\Payments\Data\PaymentSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el estado actual de un cobro según el proveedor (fase-2-diseno-pagos.md §5).
 *
 * - Se ejecuta con el Payment bloqueado: dos notificaciones simultáneas del
 *   mismo pago se aplican una después de la otra.
 * - El orden de llegada no importa: el estado viene de consultar el recurso
 *   ahora. Un estado con marca de tiempo anterior a la ya aplicada no
 *   retrocede el pago; una transición no permitida no se aplica y abre una
 *   incidencia de revisión.
 * - Los intentos se registran siempre (idempotentes por identificador externo).
 * - Solo `succeeded` crea el donativo, una vez.
 */
class SyncPayment
{
    public function __construct(
        private readonly CreateDonationFromPayment $createDonation,
        private readonly OpenPaymentIncident $openIncident,
        private readonly SyncRefund $syncRefund,
    ) {}

    public function handle(PaymentProvider $provider, PaymentSnapshot $snapshot): ?Payment
    {
        return DB::transaction(function () use ($provider, $snapshot): ?Payment {
            $payment = $this->locate($provider, $snapshot);
            if ($payment === null) {
                return null;
            }

            if ($snapshot->externalId !== null && $payment->external_id === null) {
                $payment->external_id = $snapshot->externalId;
            }

            $newlyFailed = $this->syncAttempts($payment, $snapshot->attempts);
            $previous = $payment->status;
            $isStale = $snapshot->providerUpdatedAt !== null && $payment->provider_updated_at !== null
                && $snapshot->providerUpdatedAt->lessThan($payment->provider_updated_at);

            if (! $isStale) {
                $this->applyStatus($payment, $snapshot);
            }

            $payment->save();

            if ($payment->status === PaymentStatus::Succeeded) {
                $this->createDonation->handle($payment);
            }

            $this->openIncidents($payment, $previous, $newlyFailed);

            foreach ($snapshot->refunds as $refund) {
                $this->syncRefund->applyToLockedPayment($payment, $refund);
            }

            return $payment;
        });
    }

    private function applyStatus(Payment $payment, PaymentSnapshot $snapshot): void
    {
        if ($snapshot->status !== $payment->status) {
            if (! $payment->status->canTransitionTo($snapshot->status)) {
                $this->openIncident->handle(
                    IncidentType::StateInconsistency,
                    "payment:{$payment->id}:inconsistent:{$payment->status->value}:{$snapshot->providerStatus}",
                    payment: $payment,
                );

                return;
            }

            $payment->status = $snapshot->status;
            match ($snapshot->status) {
                PaymentStatus::Succeeded => $payment->succeeded_at = $snapshot->succeededAt ?? now(),
                PaymentStatus::Failed => $payment->failed_at = now(),
                default => null,
            };
        }

        $payment->provider_status = mb_substr($snapshot->providerStatus, 0, 50);
        $payment->provider_updated_at = $snapshot->providerUpdatedAt ?? $payment->provider_updated_at;
        $payment->next_retry_owner = $snapshot->nextRetryOwner;
        $payment->next_retry_at = $snapshot->nextRetryAt;
    }

    /**
     * @param  list<AttemptSnapshot>  $attempts
     * @return list<PaymentAttempt> intentos que acaban de quedar rechazados
     */
    private function syncAttempts(Payment $payment, array $attempts): array
    {
        $newlyFailed = [];

        foreach ($attempts as $snapshot) {
            $attempt = PaymentAttempt::query()
                ->where('provider', $payment->provider->value)
                ->where('external_id', $snapshot->externalId)
                ->first();

            if ($attempt !== null && $attempt->payment_id !== $payment->id) {
                continue;
            }

            if ($attempt === null) {
                $attempt = new PaymentAttempt([
                    'payment_id' => $payment->id,
                    'provider' => $payment->provider,
                    'external_id' => $snapshot->externalId,
                    'attempt_number' => (int) PaymentAttempt::query()->where('payment_id', $payment->id)->max('attempt_number') + 1,
                    'status' => PaymentAttemptStatus::Pending,
                    'initiated_by' => $snapshot->initiatedBy,
                    'provider_created_at' => $snapshot->providerCreatedAt,
                ]);
            }

            if ($attempt->exists && $attempt->status->isFinal()) {
                continue;
            }

            $attempt->fill([
                'status' => $snapshot->status,
                'failure_category' => $snapshot->status === PaymentAttemptStatus::Failed ? $snapshot->failureCategory : null,
                'provider_code' => $snapshot->providerCode !== null ? mb_substr($snapshot->providerCode, 0, 100) : null,
                'provider_message' => $snapshot->providerMessage,
                'card_brand' => $snapshot->cardBrand,
                'card_last4' => $snapshot->cardLast4,
                'card_funding' => $snapshot->cardFunding,
            ])->save();

            if ($attempt->status === PaymentAttemptStatus::Failed) {
                $newlyFailed[] = $attempt;
            }
        }

        return $newlyFailed;
    }

    /**
     * @param  list<PaymentAttempt>  $newlyFailed
     */
    private function openIncidents(Payment $payment, PaymentStatus $previous, array $newlyFailed): void
    {
        $recurring = $payment->kind === PaymentKind::RecurringCharge;

        // Recurrentes: alerta temprana por cada rechazo (el donante no está presente).
        if ($recurring) {
            foreach ($newlyFailed as $attempt) {
                $this->openIncident->handle(IncidentType::RecurringAttemptFailed, "attempt:{$attempt->id}:failed", payment: $payment, attempt: $attempt);
            }
        }

        if ($previous === PaymentStatus::Failed || $payment->status !== PaymentStatus::Failed) {
            return;
        }

        if ($recurring) {
            $this->openIncident->handle(IncidentType::RecurringPaymentFailed, "payment:{$payment->id}:final_failed", payment: $payment);

            return;
        }

        // Pago único: solo si hay algo que revisar. Un rechazo que el donante
        // corrige (otra tarjeta, datos mal escritos) no alerta a nadie.
        $lastFailure = PaymentAttempt::query()->where('payment_id', $payment->id)
            ->where('status', PaymentAttemptStatus::Failed->value)->orderByDesc('attempt_number')->first();

        if ($lastFailure?->failure_category === null || ! $lastFailure->failure_category->isDonorCorrectable()) {
            $this->openIncident->handle(IncidentType::OneTimePaymentFailed, "payment:{$payment->id}:final_failed", payment: $payment, attempt: $lastFailure);
        }
    }

    private function locate(PaymentProvider $provider, PaymentSnapshot $snapshot): ?Payment
    {
        if ($snapshot->externalId !== null) {
            $payment = $this->lockedQuery($provider)->where('external_id', $snapshot->externalId)->first();
            if ($payment !== null) {
                return $payment;
            }
        }

        if ($snapshot->crmPaymentId !== null) {
            $payment = $this->lockedQuery($provider)->whereKey($snapshot->crmPaymentId)->first();
            if ($payment !== null && ($payment->external_id === null || $snapshot->externalId === null || $payment->external_id === $snapshot->externalId)) {
                return $payment;
            }
        }

        if ($snapshot->kind === PaymentKind::RecurringCharge) {
            return $this->locateOrCreateRecurringCharge($provider, $snapshot);
        }

        return null;
    }

    /**
     * Cada mensualidad es un Payment propio, único por suscripción y periodo.
     */
    private function locateOrCreateRecurringCharge(PaymentProvider $provider, PaymentSnapshot $snapshot): ?Payment
    {
        $subscription = $this->locateSubscription($provider, $snapshot);
        if ($subscription === null || $snapshot->billingPeriodStart === null) {
            return null;
        }

        $period = $snapshot->billingPeriodStart->toDateString();
        $existing = $this->lockedQuery($provider)->where('subscription_id', $subscription->id)
            ->whereDate('billing_period_start', $period)->first();

        if ($existing !== null) {
            if ($existing->external_id === null || $snapshot->externalId === null || $existing->external_id === $snapshot->externalId) {
                return $existing;
            }

            // Otro cobro del proveedor para un periodo que ya tiene mensualidad.
            $this->openIncident->handle(
                IncidentType::StateInconsistency,
                "payment:{$existing->id}:inconsistent:duplicate_period:{$snapshot->externalId}",
                payment: $existing,
            );

            return null;
        }

        $created = Payment::query()->createOrFirst(
            ['subscription_id' => $subscription->id, 'billing_period_start' => $period],
            [
                'provider' => $provider,
                'external_id' => $snapshot->externalId,
                'kind' => PaymentKind::RecurringCharge,
                'donor_id' => $subscription->donor_id,
                'program_id' => $subscription->program_id,
                'campaign_id' => $subscription->campaign_id,
                'amount' => $snapshot->amount ?? $subscription->amount,
                'currency' => $subscription->currency,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => "{$provider->value}:recurring:{$subscription->id}:{$period}",
            ],
        );

        return $this->lockedQuery($provider)->whereKey($created->id)->first();
    }

    private function locateSubscription(PaymentProvider $provider, PaymentSnapshot $snapshot): ?Subscription
    {
        $query = Subscription::query()->where('provider', $provider->value);

        if ($snapshot->subscriptionExternalId !== null) {
            $subscription = (clone $query)->where('external_id', $snapshot->subscriptionExternalId)->first();
            if ($subscription !== null) {
                return $subscription;
            }
        }

        return $snapshot->crmSubscriptionId !== null ? (clone $query)->whereKey($snapshot->crmSubscriptionId)->first() : null;
    }

    /**
     * @return Builder<Payment>
     */
    private function lockedQuery(PaymentProvider $provider): Builder
    {
        return Payment::query()->where('provider', $provider->value)->lockForUpdate();
    }
}
