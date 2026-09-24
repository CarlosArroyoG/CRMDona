<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\Payments\ValidateOnlineDonationAmount;
use App\Enums\PaymentProvider;
use App\Enums\Permission;
use App\Filament\Concerns\ResolvesActor;
use App\Payments\Contracts\PausesSubscriptions;
use App\Payments\Contracts\ProcessesOneTimePayments;
use App\Payments\Contracts\ProcessesRecurringPayments;
use App\Payments\Contracts\ProcessesRefunds;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\GatewayRegistry;
use App\Support\Money;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Estado de las pasarelas (solo Administrador): habilitada o no, modo de
 * prueba o producción, capacidades y límites. Nunca muestra llaves ni
 * secretos: solo si están configuradas. Se habilitan con variables de
 * entorno (docs/tecnico/integraciones-pagos.md).
 */
class PaymentGateways extends Page
{
    use ResolvesActor;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?string $navigationLabel = 'Pasarelas de pago';

    protected static ?string $title = 'Pasarelas de pago';

    protected static string|\UnitEnum|null $navigationGroup = 'Administración';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'pasarelas';

    public static function canAccess(): bool
    {
        return self::actorCan(Permission::ViewPaymentSettings);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components(array_map(
            fn (PaymentProvider $provider): Section => $this->providerSection($provider),
            [PaymentProvider::Stripe, PaymentProvider::MercadoPago, ...(app()->environment(['local', 'testing']) ? [PaymentProvider::Fake] : [])],
        ));
    }

    private function providerSection(PaymentProvider $provider): Section
    {
        $registry = app(GatewayRegistry::class);
        $gateway = null;
        try {
            $gateway = $registry->get($provider);
        } catch (GatewayNotAvailableException) {
            // Deshabilitada o sin configurar: se muestra como tal.
        }

        $limits = $gateway !== null ? app(ValidateOnlineDonationAmount::class)->effectiveLimits($gateway) : null;
        $technical = $gateway?->amountLimits();
        $capabilities = $gateway === null ? [] : array_keys(array_filter([
            'Pago único' => $gateway instanceof ProcessesOneTimePayments,
            'Mensual' => $gateway instanceof ProcessesRecurringPayments,
            'Pausar y reanudar' => $gateway instanceof PausesSubscriptions,
            'Reembolsos' => $gateway instanceof ProcessesRefunds,
        ]));

        return Section::make($provider->getLabel())->columns(3)->schema([
            TextEntry::make("{$provider->value}_enabled")->label('Estado')->badge()
                ->state($gateway !== null ? 'Habilitada' : 'Deshabilitada')
                ->color($gateway !== null ? 'success' : 'gray'),
            TextEntry::make("{$provider->value}_mode")->label('Modo')
                ->state($gateway === null ? '—' : ($gateway->isTestMode() ? 'Prueba (sandbox)' : 'Producción')),
            TextEntry::make("{$provider->value}_capabilities")->label('Capacidades')
                ->state($capabilities === [] ? '—' : implode(', ', $capabilities)),
            TextEntry::make("{$provider->value}_technical")->label('Límite técnico del proveedor')
                ->state($technical === null ? '—' : self::range($technical->min, $technical->max).($technical->source !== '' ? " ({$technical->source})" : '')),
            TextEntry::make("{$provider->value}_effective")->label('Límite aplicado (el más restrictivo)')
                ->state($limits === null ? '—' : self::range($limits['min'], $limits['max'])),
            TextEntry::make("{$provider->value}_webhook")->label('URL para registrar el webhook')
                ->state(route('webhooks.payments', ['provider' => $provider->value]))->copyable(),
        ]);
    }

    private static function range(?string $min, ?string $max): string
    {
        return 'Mínimo '.($min !== null ? Money::format($min) : 'sin límite verificado')
            .' · Máximo '.($max !== null ? Money::format($max) : 'sin límite adicional');
    }
}
