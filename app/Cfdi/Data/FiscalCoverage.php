<?php

declare(strict_types=1);

namespace App\Cfdi\Data;

use App\Enums\FiscalRoute;

/**
 * Ruta fiscal de un donativo y los motivos que impiden emitir hoy.
 * Individual sin motivos = listo para timbrar.
 */
final readonly class FiscalCoverage
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public FiscalRoute $route,
        public array $reasons = [],
    ) {}

    public function isReadyToIssue(): bool
    {
        return $this->route === FiscalRoute::Individual && $this->reasons === [];
    }
}
