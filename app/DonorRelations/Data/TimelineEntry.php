<?php

declare(strict_types=1);

namespace App\DonorRelations\Data;

use Carbon\CarbonInterface;

/**
 * Un evento del timeline 360° de un donante (docs/tecnico/gestion-relaciones-donantes.md).
 * Se construye al vuelo a partir del registro real; no se guarda en ninguna tabla.
 */
final readonly class TimelineEntry
{
    public function __construct(
        public string $source,
        public CarbonInterface $occurredAt,
        public string $title,
        public ?string $description = null,
        public ?string $actorName = null,
        public ?string $url = null,
    ) {}
}
