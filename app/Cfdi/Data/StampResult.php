<?php

declare(strict_types=1);

namespace App\Cfdi\Data;

use Carbon\CarbonImmutable;

/**
 * CFDI timbrado por el PAC: folio fiscal, XML completo y, si el PAC lo
 * genera, el PDF.
 */
final readonly class StampResult
{
    public function __construct(
        public string $uuid,
        public string $xml,
        public CarbonImmutable $stampedAt,
        public ?string $externalId = null,
        public ?string $pdf = null,
    ) {}
}
