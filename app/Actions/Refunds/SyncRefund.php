<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Enums\RefundReason;
use App\Enums\RefundSource;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Data\RefundSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el estado de un reembolso según el proveedor. Asocia el reembolso
 * con el del CRM (identificador externo, referencia propia o el pendiente sin
 * identificador del mismo importe) o registra uno hecho directamente en el
 * panel del proveedor. Nunca toca el donativo.
 */
class SyncRefund
{
    public function __construct(private readonly OpenPaymentIncident $openIncident) {}

    public function handle(PaymentProvider $provider, RefundSnapshot $snapshot): ?Refund
    {
        return DB::transaction(function () use ($provider, $snapshot): ?Refund {
            $payment = $this->locatePayment($provider, $snapshot);

            return $payment !== null ? $this->applyToLockedPayment($payment, $snapshot) : null;
        });
    }

    /**
     * Requiere el Payment ya bloqueado dentro de una transacción.
     */
    public function applyToLockedPayment(Payment $payment, RefundSnapshot $snapshot): ?Refund
    {
        $refund = $this->locateRefund($payment, $snapshot) ?? $this->recordProviderRefund($payment, $snapshot);
        if ($refund === null) {
            return null;
        }

        if ($refund->external_id === null) {
            $refund->external_id = $snapshot->externalId;
        }

        $isStale = $snapshot->providerUpdatedAt !== null && $refund->provider_updated_at !== null
            && $snapshot->providerUpdatedAt->lessThan($refund->provider_updated_at);
        $previous = $refund->status;

        if (! $isStale && $snapshot->status !== $refund->status) {
            if (! $refund->status->canTransitionTo($snapshot->status)) {
                $this->openIncident->handle(
                    IncidentType::StateInconsistency,
                    "refund:{$refund->id}:inconsistent:{$refund->status->value}:{$snapshot->status->value}",
                    refund: $refund,
                );
            } else {
                $refund->status = $snapshot->status;
                $refund->failure_reason = $snapshot->status === RefundStatus::Failed ? $snapshot->failureReason : null;
                $refund->processed_at = $snapshot->status === RefundStatus::Pending ? null : now();
                $refund->provider_updated_at = $snapshot->providerUpdatedAt ?? $refund->provider_updated_at;
            }
        }

        $refund->save();

        if ($previous !== RefundStatus::Failed && $refund->status === RefundStatus::Failed) {
            $this->openIncident->handle(IncidentType::RefundFailed, "refund:{$refund->id}:failed", refund: $refund);
        }

        return $refund;
    }

    private function locateRefund(Payment $payment, RefundSnapshot $snapshot): ?Refund
    {
        $refund = Refund::query()->where('provider', $payment->provider->value)
            ->where('external_id', $snapshot->externalId)->first();
        if ($refund !== null) {
            return $refund->payment_id === $payment->id ? $refund : null;
        }

        if ($snapshot->crmRefundId !== null) {
            $refund = Refund::query()->where('payment_id', $payment->id)->whereKey($snapshot->crmRefundId)->first();
            if ($refund !== null) {
                return $refund;
            }
        }

        // Proveedores sin metadatos en reembolsos: el pendiente del CRM que
        // todavía no tiene identificador y coincide en importe.
        return Refund::query()->where('payment_id', $payment->id)->whereNull('external_id')
            ->where('status', RefundStatus::Pending->value)->where('amount', $snapshot->amount)
            ->orderBy('id')->first();
    }

    /**
     * Reembolso hecho fuera del CRM (panel del proveedor). Si su importe
     * excede lo que queda por reembolsar, el trigger lo rechaza y se abre una
     * incidencia para revisarlo en lugar de registrar un dato imposible.
     */
    private function recordProviderRefund(Payment $payment, RefundSnapshot $snapshot): ?Refund
    {
        try {
            return DB::transaction(fn (): Refund => Refund::query()->create([
                'payment_id' => $payment->id,
                'provider' => $payment->provider,
                'external_id' => $snapshot->externalId,
                'amount' => $snapshot->amount,
                'status' => RefundStatus::Pending,
                'reason' => RefundReason::ProviderInitiated,
                'source' => RefundSource::Provider,
                'requested_at' => $snapshot->providerCreatedAt ?? now(),
                'idempotency_key' => "{$payment->provider->value}:refund:{$snapshot->externalId}",
            ]));
        } catch (QueryException $exception) {
            if ($exception->getCode() !== '23514') {
                throw $exception;
            }

            $this->openIncident->handle(
                IncidentType::StateInconsistency,
                "payment:{$payment->id}:refund_exceeds:{$snapshot->externalId}",
                payment: $payment,
            );

            return null;
        }
    }

    private function locatePayment(PaymentProvider $provider, RefundSnapshot $snapshot): ?Payment
    {
        $query = Payment::query()->where('provider', $provider->value)->lockForUpdate();

        if ($snapshot->paymentExternalId !== null) {
            $payment = (clone $query)->where('external_id', $snapshot->paymentExternalId)->first();
            if ($payment !== null) {
                return $payment;
            }
        }

        if ($snapshot->crmPaymentId !== null) {
            return (clone $query)->whereKey($snapshot->crmPaymentId)->first();
        }

        $known = Refund::query()->where('provider', $provider->value)->where('external_id', $snapshot->externalId)->value('payment_id');

        return is_int($known) ? (clone $query)->whereKey($known)->first() : null;
    }
}
