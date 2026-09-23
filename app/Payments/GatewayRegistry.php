<?php

declare(strict_types=1);

namespace App\Payments;

use App\Enums\PaymentProvider;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\MercadoPago\MercadoPagoGateway;
use App\Payments\Gateways\Stripe\StripeGateway;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/**
 * Entrega la pasarela de cada proveedor según la configuración. No existe
 * una pasarela global: cada pago usa la de su proveedor. La simulada solo
 * se entrega en local y testing.
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGateway> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @throws GatewayNotAvailableException
     */
    public function get(PaymentProvider $provider): PaymentGateway
    {
        if (! $this->isEnabled($provider)) {
            throw new GatewayNotAvailableException("La pasarela {$provider->getLabel()} no está habilitada en este entorno.");
        }

        return $this->resolved[$provider->value] ??= $this->container->make(match ($provider) {
            PaymentProvider::Stripe => StripeGateway::class,
            PaymentProvider::MercadoPago => MercadoPagoGateway::class,
            PaymentProvider::Fake => FakeGateway::class,
        });
    }

    /**
     * Para acciones de personas en el CRM: una pasarela deshabilitada se
     * informa como error de validación, no como falla del sistema.
     *
     * @throws ValidationException
     */
    public function getForUserAction(PaymentProvider $provider): PaymentGateway
    {
        try {
            return $this->get($provider);
        } catch (GatewayNotAvailableException $exception) {
            throw ValidationException::withMessages(['provider' => $exception->getMessage()]);
        }
    }

    public function isEnabled(PaymentProvider $provider): bool
    {
        if (! (bool) config("payments.providers.{$provider->value}.enabled")) {
            return false;
        }

        return $provider !== PaymentProvider::Fake || app()->environment(['local', 'testing']);
    }

    /**
     * @return list<PaymentProvider>
     */
    public function enabledProviders(): array
    {
        return array_values(array_filter(PaymentProvider::cases(), $this->isEnabled(...)));
    }

    /**
     * Olvida las instancias creadas (por ejemplo, tras cambiar la configuración en pruebas).
     */
    public function flush(): void
    {
        $this->resolved = [];
    }
}
