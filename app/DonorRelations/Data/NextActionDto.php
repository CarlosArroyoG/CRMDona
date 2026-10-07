<?php

declare(strict_types=1);

namespace App\DonorRelations\Data;

use Illuminate\Support\Carbon;

/**
 * Próxima acción pendiente de un donante, derivada (no guardada) a partir de
 * sus actividades programadas y tareas abiertas (docs/tecnico/gestion-relaciones-donantes.md).
 */
final readonly class NextActionDto
{
    public function __construct(
        public string $title,
        public Carbon $dueAt,
        public string $url,
    ) {}
}
