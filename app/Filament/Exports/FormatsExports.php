<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use DateTimeInterface;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Comportamiento común de los exportadores: mensaje final en español y
 * fechas legibles en hojas de cálculo.
 */
trait FormatsExports
{
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Tu exportación está lista: '.Number::format($export->successful_rows).' '
            .($export->successful_rows === 1 ? 'registro' : 'registros').'. El archivo se elimina en 7 días.';

        $failed = $export->getFailedRowsCount();
        if ($failed > 0) {
            $body .= ' '.Number::format($failed).' '.($failed === 1 ? 'registro no se pudo' : 'registros no se pudieron').' exportar.';
        }

        return $body;
    }

    protected static function date(mixed $state): string
    {
        return $state instanceof DateTimeInterface ? $state->format('Y-m-d') : '';
    }

    protected static function dateTime(mixed $state): string
    {
        return $state instanceof DateTimeInterface ? $state->format('Y-m-d H:i') : '';
    }
}
