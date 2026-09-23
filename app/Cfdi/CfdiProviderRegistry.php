<?php

declare(strict_types=1);

namespace App\Cfdi;

use App\Cfdi\Contracts\CfdiProvider;
use App\Cfdi\Providers\FakeCfdiProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Validation\ValidationException;

/**
 * Entrega el PAC configurado (`cfdi.provider`). El simulado solo existe en
 * local y testing. El PAC real se agregará al aprobarse su elección.
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

        return $this->resolved ??= $this->container->make($class);
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
            default => null,
        };
    }
}
