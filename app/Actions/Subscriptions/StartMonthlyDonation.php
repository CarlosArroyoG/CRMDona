<?php

declare(strict_types=1);

namespace App\Actions\Subscriptions;

use App\Actions\Payments\ApplyProviderSnapshot;
use App\Actions\Payments\ValidateOnlineDonationAmount;
use App\Actions\Payments\ValidatesOnlineDonationInput;
use App\Enums\SubscriptionInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\ProcessesRecurringPayments;
use App\Payments\Data\CheckoutResult;
use App\Payments\Data\SubscriptionRequest;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\GatewayRegistry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inicia un donativo mensual. Igual que el único: idempotente por la llave
 * del formulario, la Subscription se guarda antes de llamar al proveedor y
 * la llamada ocurre fuera de transacción. Quién reintenta los cobros lo
 * decide el producto del proveedor (retry_owner).
 */
class StartMonthlyDonation
{
    use ValidatesOnlineDonationInput;

    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly ValidateOnlineDonationAmount $limits,
        private readonly ApplyProviderSnapshot $applySnapshot,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{subscription: Subscription, checkout: CheckoutResult}
     *
     * @throws ValidationException
     * @throws PaymentProviderException
     */
    public function handle(array $input): array
    {
        $data = $this->validatedOnlineInput($input, ProcessesRecurringPayments::class, $this->registry, $this->limits);
        /** @var ProcessesRecurringPayments&PaymentGateway $gateway */
        $gateway = $data['gateway'];

        if (! in_array(SubscriptionInterval::Monthly, $gateway->supportedIntervals(), true)) {
            throw ValidationException::withMessages(['provider' => 'Ese proveedor de pago no admite donativos mensuales.']);
        }

        $subscription = Subscription::query()->createOrFirst(['idempotency_key' => $data['idempotency_key']], [
            'provider' => $data['provider'],
            'donor_id' => $data['donor']->id,
            'program_id' => $data['program_id'],
            'campaign_id' => $data['campaign_id'],
            'amount' => $data['amount'],
            'currency' => 'MXN',
            'interval' => SubscriptionInterval::Monthly,
            'status' => SubscriptionStatus::Pending,
            'retry_owner' => $gateway->retryOwner(),
            'tax_receipt_requested' => $data['tax_receipt_requested'],
        ]);

        if (! $subscription->wasRecentlyCreated && ($subscription->donor_id !== $data['donor']->id
            || $subscription->provider !== $data['provider'] || Money::compare($subscription->amount, $data['amount']) !== 0)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta solicitud ya se registró con otros datos. Recarga la página e intenta de nuevo.']);
        }

        if ($subscription->status !== SubscriptionStatus::Pending) {
            return ['subscription' => $subscription, 'checkout' => new CheckoutResult(subscriptionExternalId: $subscription->external_id)];
        }

        $checkout = $gateway->startSubscription(new SubscriptionRequest(
            subscriptionId: $subscription->id,
            idempotencyKey: $subscription->idempotency_key,
            amount: $subscription->amount,
            currency: $subscription->currency,
            interval: $subscription->interval,
            description: $this->donationDescription($subscription->campaign_id).' (mensual)',
            donorEmail: $data['donor']->email,
            returnUrl: config()->string('payments.return_url'),
            cardToken: $data['card_token'],
        ));

        if ($checkout->subscriptionExternalId !== null) {
            DB::transaction(function () use ($subscription, $checkout): void {
                $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
                if ($locked->external_id === null) {
                    $locked->forceFill(['external_id' => $checkout->subscriptionExternalId])->save();
                }
            });
        }

        if ($checkout->snapshot !== null) {
            $this->applySnapshot->handle($subscription->provider, $checkout->snapshot);
        }

        return ['subscription' => $subscription->refresh(), 'checkout' => $checkout];
    }
}
