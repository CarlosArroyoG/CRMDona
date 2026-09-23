<?php

declare(strict_types=1);

namespace App\Cfdi\Contracts;

use App\Cfdi\Exceptions\CfdiProviderUnavailableException;

/**
 * Capacidad opcional: el PAC genera la representación impresa (PDF).
 */
interface RendersCfdiPdf
{
    /**
     * @throws CfdiProviderUnavailableException
     */
    public function pdf(string $uuid, ?string $externalId): string;
}
