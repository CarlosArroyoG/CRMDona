<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Enums\AuditEvent;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Contracts\PausesSubscriptions;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\GatewayRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pausa un donativo mensual en el proveedor (si lo admite) y lo refleja en
 * el CRM solo cuando el proveedor lo confirma. La llamada ocurre fuera de
 * transacción.
 */
class PauseSubscription
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

        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true)) {
            throw ValidationException::withMessages(['subscription' => 'Solo se pausa un donativo mensual activo o con cobro pendiente.']);
        }

        $gateway = $this->registry->getForUserAction($subscription->provider);
        if (! $gateway instanceof PausesSubscriptions || $subscription->external_id === null) {
            throw ValidationException::withMessages(['subscription' => 'Este proveedor no permite pausar el donativo mensual desde el CRM.']);
        }

        $snapshot = $gateway->pauseSubscription($subscription->external_id);
        if ($snapshot->status !== SubscriptionStatus::Paused) {
            throw ValidationException::withMessages(['subscription' => 'El proveedor no confirmó la pausa. Revisa el estado e intenta de nuevo.']);
        }

        return DB::transaction(function () use ($subscription, $snapshot, $reason, $actor): Subscription {
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            if ($locked->status === SubscriptionStatus::Paused) {
                return $locked;
            }

            $locked->auditAs(AuditEvent::Paused, ['reason' => $reason])->forceFill([
                'status' => SubscriptionStatus::Paused,
                'paused_at' => now(),
                'paused_by_id' => $actor->id,
                'provider_status' => mb_substr($snapshot->providerStatus, 0, 50),
                'provider_updated_at' => $snapshot->providerUpdatedAt ?? $locked->provider_updated_at,
            ])->save();

            return $locked;
        });
    }
}
