<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Actions\Imports\Models\Import as FilamentImport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Importación de Filament (carga masiva de donantes por CSV). Las filas
 * rechazadas guardan los datos del archivo: se eliminan a los 7 días con
 * `model:prune`, junto con su importación (en cascada), como las
 * exportaciones (ADR-007). Los donantes importados nunca se borran.
 */
class Import extends FilamentImport
{
    public const RETENTION_DAYS = 7;

    protected $table = 'imports';

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }
}
