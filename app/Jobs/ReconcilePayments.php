<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Actions\Payments\ApplyProviderSnapshot;
use App\Actions\Payments\CreateDonationFromPayment;
use App\Actions\Refunds\SubmitRefundToProvider;
use App\Enums\AuditSource;
use App\Enums\IncidentType;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\GatewayRegistry;
use App\Support\AuditOrigin;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Conciliación periódica (programada): cubre lo que una notificación perdida
 * o un timeout pudieran dejar a medias. No reintenta cobros: eso lo decide
 * el proveedor.
 *
 * 1. Reembolsos pendientes que nunca obtuvieron respuesta: se reenvían con
 *    su misma llave de idempotencia (el proveedor no los duplica).
 * 2. Pagos y reembolsos que siguen en proceso: se consulta su estado actual.
 * 3. Pagos exitosos sin donativo: se crea el donativo o se abre incidencia.
 */
class ReconcilePayments implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function handle(
        GatewayRegistry $registry,
        SubmitRefundToProvider $submitRefund,
        ApplyProviderSnapshot $apply,
        CreateDonationFromPayment $createDonation,
        OpenPaymentIncident $openIncident,
        AuditOrigin $origin,
    ): void {
        $origin->run(AuditSource::Synchronization, function () use ($registry, $submitRefund, $apply, $createDonation, $openIncident): void {
            $this->resubmitRefunds($submitRefund, $openIncident);
            $this->refreshStalePayments($registry, $apply);
            $this->refreshPendingRefunds($registry, $apply);
            $this->createMissingDonations($createDonation, $openIncident);
        });
    }

    private function resubmitRefunds(SubmitRefundToProvider $submitRefund, OpenPaymentIncident $openIncident): void
    {
        $refunds = Refund::query()->where('status', RefundStatus::Pending->value)->whereNull('external_id')
            ->where('requested_at', '<=', now()->subMinutes(config()->integer('payments.reconciliation.refund_resubmit_after_minutes')))
            ->orderBy('id')->limit(100)->get();

        $alertAfter = now()->subMinutes(config()->integer('payments.reconciliation.refund_unavailable_alert_after_minutes'));

        foreach ($refunds as $refund) {
            $this->safely(fn () => $submitRefund->handle($refund));

            $refund->refresh();
            if ($refund->status === RefundStatus::Pending && $refund->external_id === null && $refund->requested_at->lessThanOrEqualTo($alertAfter)) {
                $openIncident->handle(
                    IncidentType::ProviderUnavailable,
                    "provider:{$refund->provider->value}:unavailable:".now()->format('Y-m-d\TH'),
                    refund: $refund,
                );
            }
        }
    }

    private function refreshStalePayments(GatewayRegistry $registry, ApplyProviderSnapshot $apply): void
    {
        $payments = Payment::query()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value])
            ->whereNotNull('external_id')
            ->where('updated_at', '<=', now()->subMinutes(config()->integer('payments.reconciliation.stale_after_minutes')))
            ->orderBy('id')->limit(100)->get();

        foreach ($payments as $payment) {
            $this->safely(fn () => $apply->handle($payment->provider, $registry->get($payment->provider)->fetchPayment($payment)));
        }
    }

    private function refreshPendingRefunds(GatewayRegistry $registry, ApplyProviderSnapshot $apply): void
    {
        $refunds = Refund::query()->where('status', RefundStatus::Pending->value)->whereNotNull('external_id')
            ->where('updated_at', '<=', now()->subMinutes(config()->integer('payments.reconciliation.stale_after_minutes')))
            ->orderBy('id')->limit(100)->get();

        foreach ($refunds as $refund) {
            $this->safely(fn () => $apply->handle($refund->provider, $registry->get($refund->provider)->fetchRefund($refund)));
        }
    }

    private function createMissingDonations(CreateDonationFromPayment $createDonation, OpenPaymentIncident $openIncident): void
    {
        $payments = Payment::query()->where('status', PaymentStatus::Succeeded->value)->whereDoesntHave('donation')
            ->orderBy('id')->limit(100)->get();

        foreach ($payments as $payment) {
            try {
                DB::transaction(fn () => $createDonation->handle(Payment::query()->lockForUpdate()->findOrFail($payment->id)));
            } catch (Throwable $exception) {
                Log::error('Pago exitoso sin donativo.', ['payment_id' => $payment->id, 'error' => $exception::class]);
                $openIncident->handle(IncidentType::SucceededWithoutDonation, "payment:{$payment->id}:missing_donation", payment: $payment);
            }
        }
    }

    /**
     * Un proveedor caído o deshabilitado no detiene la conciliación del resto.
     */
    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (PaymentProviderException|GatewayNotAvailableException $exception) {
            Log::warning('Conciliación: el proveedor no respondió.', ['error' => $exception->getMessage()]);
        }
    }
}
