<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Enums\IncidentType;
use App\Enums\PaymentAttemptStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Payments\Contracts\ProcessesRefunds;
use App\Payments\Data\RefundRequest;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Payments\GatewayRegistry;
use App\Support\SensitiveData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Envía al proveedor un reembolso pendiente, siempre con su misma llave de
 * idempotencia. Si el proveedor no responde (timeout) el reembolso queda
 * pendiente y la conciliación lo reenvía con la misma llave: el proveedor
 * no lo duplica. No se mantiene ninguna transacción abierta durante la
 * llamada.
 */
class SubmitRefundToProvider
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly SyncRefund $syncRefund,
        private readonly OpenPaymentIncident $openIncident,
    ) {}

    public function handle(Refund $refund): Refund
    {
        if ($refund->status !== RefundStatus::Pending || $refund->external_id !== null) {
            return $refund;
        }

        $gateway = $this->registry->get($refund->provider);
        if (! $gateway instanceof ProcessesRefunds) {
            return $refund;
        }

        $payment = $refund->payment;
        $attemptId = PaymentAttempt::query()->where('payment_id', $payment->id)
            ->where('status', PaymentAttemptStatus::Succeeded->value)->orderByDesc('attempt_number')->value('external_id');

        try {
            $snapshot = $gateway->refund(new RefundRequest(
                refundId: $refund->id,
                paymentId: $payment->id,
                idempotencyKey: $refund->idempotency_key,
                amount: $refund->amount,
                paymentExternalId: $payment->external_id,
                attemptExternalId: is_string($attemptId) ? $attemptId : null,
                providerReason: $refund->provider_reason,
            ));
        } catch (ProviderUnavailableException $exception) {
            Log::warning('Reembolso sin respuesta del proveedor; se reintentará con la misma llave.', [
                'refund_id' => $refund->id, 'provider' => $refund->provider->value, 'error' => $exception->getMessage(),
            ]);

            return $refund;
        } catch (ProviderRejectedException $exception) {
            return $this->markRejected($refund, $exception);
        }

        return DB::transaction(function () use ($refund, $snapshot): Refund {
            $payment = Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $locked = Refund::query()->findOrFail($refund->id);

            if ($locked->external_id === null) {
                $locked->forceFill(['external_id' => $snapshot->externalId])->save();
            }

            return $this->syncRefund->applyToLockedPayment($payment, $snapshot) ?? $locked;
        });
    }

    private function markRejected(Refund $refund, ProviderRejectedException $exception): Refund
    {
        return DB::transaction(function () use ($refund, $exception): Refund {
            Payment::query()->lockForUpdate()->findOrFail($refund->payment_id);
            $locked = Refund::query()->findOrFail($refund->id);

            if ($locked->status === RefundStatus::Pending) {
                $locked->forceFill([
                    'status' => RefundStatus::Failed,
                    'failure_reason' => SensitiveData::safeText($exception->getMessage(), 255),
                    'processed_at' => now(),
                ])->save();

                $this->openIncident->handle(IncidentType::RefundFailed, "refund:{$locked->id}:failed", refund: $locked);
            }

            return $locked;
        });
    }
}
