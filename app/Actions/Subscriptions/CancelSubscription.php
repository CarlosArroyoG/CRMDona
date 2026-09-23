<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\AuditEvent;
use App\Enums\CancellationSource;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Contracts\ProcessesRecurringPayments;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\GatewayRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cancela un donativo mensual a petición de una persona del CRM. Antes de
 * llamar al proveedor se registra la intención (quién y por qué): así la
 * notificación de cancelación que envíe el proveedor no se confunde con una
 * cancelación hecha por él y no abre una incidencia falsa. Si el proveedor
 * falla, la intención se retira.
 */
class CancelSubscription
{
    use ValidatesSubscriptionChange;

    public function __construct(private readonly GatewayRegistry $registry) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws PaymentProviderException
     */
    public function handle(Subscription $subscription, ?string $reason, User $actor): Subscription
    {
        $reason = $this->validatedReason($actor, $reason);

        $intent = DB::transaction(function () use ($subscription, $reason, $actor): Subscription {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($locked->status->isFinal()) {
                throw ValidationException::withMessages(['subscription' => 'Este donativo mensual ya está cancelado o nunca se activó.']);
            }

            $locked->forceFill([
                'cancellation_source' => CancellationSource::CrmUser,
                'cancelled_by_id' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();

            return $locked;
        });

        try {
            $snapshot = null;
            if ($intent->external_id !== null) {
                $gateway = $this->registry->getForUserAction($intent->provider);
                if (! $gateway instanceof ProcessesRecurringPayments) {
                    throw ValidationException::withMessages(['subscription' => 'Este proveedor no permite cancelar desde el CRM.']);
                }
                $snapshot = $gateway->cancelSubscription($intent->external_id);
            }
        } catch (Throwable $exception) {
            $this->withdrawIntent($intent);

            throw $exception;
        }

        return DB::transaction(function () use ($intent, $snapshot, $reason): Subscription {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($intent->id);
            if ($locked->status === SubscriptionStatus::Cancelled) {
                return $locked;
            }

            $locked->auditAs(AuditEvent::Cancelled, ['reason' => $reason])->forceFill([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => $snapshot->cancelledAt ?? now(),
                'provider_status' => $snapshot !== null ? mb_substr($snapshot->providerStatus, 0, 50) : $locked->provider_status,
                'provider_updated_at' => $snapshot->providerUpdatedAt ?? $locked->provider_updated_at,
                'next_charge_at' => null,
            ])->save();

            return $locked;
        });
    }

    private function withdrawIntent(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription): void {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($locked->status !== SubscriptionStatus::Cancelled) {
                $locked->forceFill(['cancellation_source' => null, 'cancelled_by_id' => null, 'cancellation_reason' => null])->save();
            }
        });
    }
}
