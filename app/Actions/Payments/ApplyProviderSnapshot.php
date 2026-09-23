<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Disputes\SyncDispute;
use App\Actions\Refunds\SyncRefund;
use App\Actions\Subscriptions\SyncSubscription;
use App\Enums\PaymentProvider;
use App\Payments\Data\ProviderSnapshot;

/**
 * Aplica todo lo que el proveedor reporta sobre un recurso consultado. Las
 * suscripciones van primero: una mensualidad nueva necesita su suscripción.
 * Devuelve los registros del CRM afectados para enlazarlos a la notificación.
 */
class ApplyProviderSnapshot
{
    public function __construct(
        private readonly SyncSubscription $syncSubscription,
        private readonly SyncPayment $syncPayment,
        private readonly SyncRefund $syncRefund,
        private readonly SyncDispute $syncDispute,
    ) {}

    /**
     * @return array{payment_id: int|null, subscription_id: int|null, refund_id: int|null, dispute_id: int|null}
     */
    public function handle(PaymentProvider $provider, ProviderSnapshot $snapshot): array
    {
        $references = ['payment_id' => null, 'subscription_id' => null, 'refund_id' => null, 'dispute_id' => null];

        foreach ($snapshot->subscriptions as $subscription) {
            $references['subscription_id'] ??= $this->syncSubscription->handle($provider, $subscription)?->id;
        }

        foreach ($snapshot->payments as $payment) {
            $synced = $this->syncPayment->handle($provider, $payment);
            $references['payment_id'] ??= $synced?->id;
            $references['subscription_id'] ??= $synced?->subscription_id;
        }

        foreach ($snapshot->refunds as $refund) {
            $synced = $this->syncRefund->handle($provider, $refund);
            $references['refund_id'] ??= $synced?->id;
            $references['payment_id'] ??= $synced?->payment_id;
        }

        foreach ($snapshot->disputes as $dispute) {
            $synced = $this->syncDispute->handle($provider, $dispute);
            $references['dispute_id'] ??= $synced->id;
            $references['payment_id'] ??= $synced->payment_id;
        }

        return $references;
    }
}
