<?php

declare(strict_types=1);

namespace App\Cfdi;

use App\Cfdi\Contracts\CfdiProvider;
use App\Cfdi\Providers\FacturapiCfdiProvider;
use App\Cfdi\Providers\FakeCfdiProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/**
 * Entrega el PAC configurado (`cfdi.provider`). El simulado solo existe en
 * local y testing; Facturapi, con su llave en el entorno.
 */
final class CfdiProviderRegistry
{
    private ?CfdiProvider $resolved = null;

    public function __construct(private readonly Container $container) {}

    public function isConfigured(): bool
    {
        return $this->providerClass() !== null;
    }

    /**
     * @throws ValidationException
     */
    public function current(): CfdiProvider
    {
        $class = $this->providerClass()
            ?? throw ValidationException::withMessages(['cfdi' => 'No hay un proveedor de CFDI (PAC) configurado en este entorno.']);

        return $this->resolved ??= match ($class) {
            FacturapiCfdiProvider::class => new FacturapiCfdiProvider(config()->string('cfdi.facturapi.key')),
            default => $this->container->make($class),
        };
    }

    public function flush(): void
    {
        $this->resolved = null;
    }

    /**
     * @return class-string<CfdiProvider>|null
     */
    private function providerClass(): ?string
    {
        return match (config('cfdi.provider')) {
            'fake' => app()->environment(['local', 'testing']) ? FakeCfdiProvider::class : null,
            'facturapi' => $this->facturapiKeyAllowed() ? FacturapiCfdiProvider::class : null,
            default => null,
        };
    }

    /**
     * Llave de prueba en cualquier entorno; llave real (`sk_live_`) solo en
     * producción, para que un entorno local nunca timbre CFDI con validez.
     */
    private function facturapiKeyAllowed(): bool
    {
        $key = config('cfdi.facturapi.key');

        return is_string($key) && (str_starts_with($key, 'sk_test_') || (str_starts_with($key, 'sk_live_') && app()->isProduction()));
    }
}
