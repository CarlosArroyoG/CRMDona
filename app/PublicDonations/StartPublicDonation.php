<?php

declare(strict_types=1);

namespace App\PublicDonations;

use App\Actions\Payments\StartOneTimeDonation;
use App\Actions\Subscriptions\StartMonthlyDonation;
use App\Enums\PaymentProvider;
use App\Models\Campaign;
use App\Models\Payment;
use App\Models\Subscription;
use App\Payments\Data\CheckoutResult;
use App\Payments\Exceptions\PaymentProviderException;
use Illuminate\Validation\ValidationException;

/**
 * Inicia el pago de un donativo público con los datos validados que guardó
 * el servidor (nunca con importe, campaña o frecuencia del navegador). Solo
 * orquesta: el Payment/Subscription, la idempotencia, los límites y la
 * confirmación los hacen StartOneTimeDonation / StartMonthlyDonation y el
 * flujo seguro del proveedor (webhook o consulta), igual que en la Fase 2.
 */
class StartPublicDonation
{
    public function __construct(
        private readonly ResolvePublicDonor $donors,
        private readonly StartOneTimeDonation $oneTime,
        private readonly StartMonthlyDonation $monthly,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{donor_id: int, payment: Payment|null, subscription: Subscription|null, checkout: CheckoutResult}
     *
     * @throws ValidationException
     * @throws PaymentProviderException
     */
    public function handle(array $payload, PaymentProvider $provider, ?string $cardToken = null, ?string $paymentMethodId = null): array
    {
        $campaignId = is_int($payload['campaign_id'] ?? null) ? $payload['campaign_id'] : null;
        if ($campaignId !== null && ! (Campaign::query()->with('program')->find($campaignId)?->acceptsDonations() ?? false)) {
            throw ValidationException::withMessages(['campaign' => 'Esta campaña ya no está recibiendo donativos.']);
        }

        $donorId = is_int($payload['donor_id'] ?? null) ? $payload['donor_id'] : $this->donors->handle($payload)->id;

        $input = [
            'provider' => $provider->value,
            'donor_id' => $donorId,
            'campaign_id' => $campaignId,
            'amount' => $payload['amount'],
            'idempotency_key' => $payload['idempotency_key'],
            'card_token' => $cardToken,
            'payment_method_id' => $paymentMethodId,
            'tax_receipt_requested' => ($payload['tax'] ?? null) !== null,
        ];

        if ($payload['frequency'] === ValidatePublicDonationForm::MONTHLY) {
            $result = $this->monthly->handle($input);

            return ['donor_id' => $donorId, 'payment' => null, 'subscription' => $result['subscription'], 'checkout' => $result['checkout']];
        }

        $result = $this->oneTime->handle($input);

        return ['donor_id' => $donorId, 'payment' => $result['payment'], 'subscription' => null, 'checkout' => $result['checkout']];
    }
}
