<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Actions\Exports\Models\Export as FilamentExport;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exportación de Filament (ADR-007). Los archivos contienen datos personales:
 * se eliminan a los 7 días con `model:prune`. Solo se borra el archivo
 * temporal y su registro, nunca los datos originales.
 */
class Export extends FilamentExport
{
    public const RETENTION_DAYS = 7;

    protected $table = 'exports';

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<=', now()->subDays(self::RETENTION_DAYS));
    }

    protected function pruning(): void
    {
        $this->deleteFileDirectory();
    }
}
