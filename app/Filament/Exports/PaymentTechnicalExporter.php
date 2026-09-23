<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\PaymentAttemptStatus;
use App\Models\Payment;
use Filament\Actions\Exports\ExportColumn;

/**
 * Exportación con detalle técnico (Administrador y Contador): agrega
 * identificador y estado en el proveedor, número de intentos y el motivo del
 * último rechazo. Nunca payloads, datos de tarjeta ni secretos.
 */
class PaymentTechnicalExporter extends PaymentExporter
{
    public static function getColumns(): array
    {
        return [
            ...parent::getColumns(),
            ExportColumn::make('external_id')->label('Identificador en el proveedor'),
            ExportColumn::make('provider_status')->label('Estado en el proveedor'),
            ExportColumn::make('attempts_count')->label('Intentos')->counts('attempts'),
            ExportColumn::make('last_failure')->label('Motivo del último rechazo')
                ->state(fn (Payment $record): string => $record->attempts()
                    ->where('status', PaymentAttemptStatus::Failed->value)->reorder('attempt_number', 'desc')
                    ->first()?->failure_category?->getLabel() ?? ''),
        ];
    }
}
