<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Enums\CancellationSource;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Payments\Data\SubscriptionSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Aplica el estado actual de una suscripción según el proveedor. Nunca la
 * cancela por un cobro fallido: solo refleja lo que el proveedor reporta.
 * Si el proveedor la cancela sin que nadie lo pidiera en el CRM, abre una
 * incidencia.
 */
class SyncSubscription
{
    public function __construct(private readonly OpenPaymentIncident $openIncident) {}

    public function handle(PaymentProvider $provider, SubscriptionSnapshot $snapshot): ?Subscription
    {
        return DB::transaction(function () use ($provider, $snapshot): ?Subscription {
            $subscription = $this->locate($provider, $snapshot);
            if ($subscription === null) {
                return null;
            }

            if ($snapshot->externalId !== null && $subscription->external_id === null) {
                $subscription->external_id = $snapshot->externalId;
            }

            $isStale = $snapshot->providerUpdatedAt !== null && $subscription->provider_updated_at !== null
                && $snapshot->providerUpdatedAt->lessThan($subscription->provider_updated_at);
            $cancelledByProvider = false;

            if (! $isStale) {
                $cancelledByProvider = $this->applyStatus($subscription, $snapshot);
                $subscription->provider_status = mb_substr($snapshot->providerStatus, 0, 50);
                $subscription->provider_updated_at = $snapshot->providerUpdatedAt ?? $subscription->provider_updated_at;
                $subscription->next_charge_at = $snapshot->nextChargeAt;
                if ($snapshot->amount !== null) {
                    $subscription->amount = $snapshot->amount;
                }
            }

            $subscription->save();

            if ($cancelledByProvider) {
                $this->openIncident->handle(
                    IncidentType::SubscriptionCancelledByProvider,
                    "subscription:{$subscription->id}:cancelled_by_provider",
                    subscription: $subscription,
                );
            }

            return $subscription;
        });
    }

    /**
     * @return bool si el proveedor la canceló por su cuenta
     */
    private function applyStatus(Subscription $subscription, SubscriptionSnapshot $snapshot): bool
    {
        $current = $subscription->status;
        $next = $snapshot->status;

        if ($next === $current) {
            return false;
        }

        if (! $current->canTransitionTo($next)) {
            $this->openIncident->handle(
                IncidentType::StateInconsistency,
                "subscription:{$subscription->id}:inconsistent:{$current->value}:{$snapshot->providerStatus}",
                subscription: $subscription,
            );

            return false;
        }

        $subscription->status = $next;

        switch ($next) {
            case SubscriptionStatus::Active:
                $subscription->started_at ??= $snapshot->startedAt ?? now();
                if ($current === SubscriptionStatus::Paused) {
                    $subscription->resumed_at = now();
                }
                break;
            case SubscriptionStatus::Paused:
                $subscription->paused_at = now();
                break;
            case SubscriptionStatus::Cancelled:
                $subscription->cancelled_at ??= $snapshot->cancelledAt ?? now();
                // Si alguien la canceló desde el CRM, la fuente ya quedó registrada.
                if ($subscription->cancellation_source === null) {
                    $subscription->cancellation_source = CancellationSource::Provider;

                    return true;
                }
                break;
            default:
                break;
        }

        return false;
    }

    private function locate(PaymentProvider $provider, SubscriptionSnapshot $snapshot): ?Subscription
    {
        $query = Subscription::query()->where('provider', $provider->value)->lockForUpdate();

        if ($snapshot->externalId !== null) {
            $subscription = (clone $query)->where('external_id', $snapshot->externalId)->first();
            if ($subscription !== null) {
                return $subscription;
            }
        }

        if ($snapshot->crmSubscriptionId !== null) {
            $subscription = (clone $query)->whereKey($snapshot->crmSubscriptionId)->first();
            if ($subscription !== null && ($subscription->external_id === null || $snapshot->externalId === null
                || $subscription->external_id === $snapshot->externalId)) {
                return $subscription;
            }
        }

        return null;
    }
}
