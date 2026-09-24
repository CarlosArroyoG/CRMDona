<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Histórico: motivos de cancelación del SAT registrados cuando el CRM todavía
 * cancelaba CFDI. Solo se usa para leer `cfdis` (docs/tecnico/cfdi-externo.md).
 */
enum CfdiCancellationMotive: string implements HasLabel
{
    case ErrorsWithRelation = '01';
    case ErrorsWithoutRelation = '02';
    case OperationNotCarriedOut = '03';
    case NominativeInGlobal = '04';

    public function getLabel(): string
    {
        return match ($this) {
            self::ErrorsWithRelation => '01 — Comprobante emitido con errores con relación',
            self::ErrorsWithoutRelation => '02 — Comprobante emitido con errores sin relación',
            self::OperationNotCarriedOut => '03 — No se llevó a cabo la operación',
            self::NominativeInGlobal => '04 — Operación nominativa relacionada en factura global',
        };
    }
}
