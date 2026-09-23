<?php

declare(strict_types=1);

namespace App\PublicDonations;

use App\Actions\Payments\ValidateOnlineDonationAmount;
use App\Enums\PaymentProvider;
use App\Models\Campaign;
use App\Models\OrganizationSetting;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\ProcessesRecurringPayments;
use App\Payments\GatewayRegistry;

/**
 * Qué puede ofrecer la página pública ahora: proveedor, límites, si acepta
 * mensualidades y si la campaña del enlace puede recibir donativos. Toda la
 * validación de importes la sigue haciendo la capa de pagos al iniciar.
 */
final class PublicDonationPage
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly ValidateOnlineDonationAmount $limits,
    ) {}

    public function provider(): ?PaymentProvider
    {
        $configured = PaymentProvider::tryFrom((string) config('donations.public.provider'));
        if ($configured !== null) {
            return $this->registry->isEnabled($configured) ? $configured : null;
        }

        foreach ([PaymentProvider::Stripe, PaymentProvider::MercadoPago, PaymentProvider::Fake] as $provider) {
            if ($this->registry->isEnabled($provider)) {
                return $provider;
            }
        }

        return null;
    }

    public function gateway(): ?PaymentGateway
    {
        $provider = $this->provider();

        return $provider !== null ? $this->registry->get($provider) : null;
    }

    /**
     * Motivo por el que no se puede donar ahora, o nulo si se puede. Texto
     * para el público: sin detalles internos.
     */
    public function unavailableReason(?Campaign $campaign, bool $campaignRequested): ?string
    {
        return match (true) {
            $campaignRequested && ($campaign === null || ! $campaign->acceptsDonations()) => 'Esta campaña no está recibiendo donativos en este momento.',
            ! OrganizationSetting::current()->hasPrivacyNotice() => 'Los donativos en línea no están disponibles por ahora.',
            $this->gateway() === null => 'Los donativos en línea no están disponibles por ahora.',
            default => null,
        };
    }

    public function acceptsMonthly(): bool
    {
        return $this->gateway() instanceof ProcessesRecurringPayments;
    }

    /**
     * @return array{min: numeric-string|null, max: numeric-string|null}
     */
    public function amountLimits(): array
    {
        $gateway = $this->gateway();

        return $gateway !== null ? $this->limits->effectiveLimits($gateway) : ['min' => null, 'max' => null];
    }

    /**
     * @return list<string>
     */
    public function suggestedAmounts(): array
    {
        /** @var list<string> $amounts */
        $amounts = config()->array('donations.public.suggested_amounts');

        return array_values(array_filter($amounts, fn (string $amount): bool => preg_match('/^\d+(\.\d{1,2})?$/', $amount) === 1));
    }
}
