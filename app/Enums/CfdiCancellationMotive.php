<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Motivos de cancelación del SAT [V] ("Esquema de cancelación de CFDI 2026"):
 * - 01: la operación subsiste; primero se timbra el CFDI nuevo relacionado
 *   (TipoRelacion 04) y se cancela el original indicando su UUID;
 * - 02: error sin relación (por ejemplo, RFC totalmente erróneo); se cancela
 *   primero y después se emite el correcto;
 * - 03: la operación no se llevó a cabo;
 * - 04: solo para una factura global cuando el cliente pide su CFDI
 *   nominativo (la factura global aún no está habilitada, [F]).
 */
enum CfdiCancellationMotive: string implements HasLabel
{
    case ErrorsWithRelation = '01';
    case ErrorsWithoutRelation = '02';
    case OperationNotCarriedOut = '03';
    case NominativeInGlobal = '04';

    public function requiresReplacement(): bool
    {
        return $this === self::ErrorsWithRelation;
    }

    /**
     * Motivos que se solicitan directamente sobre un CFDI individual. El 01
     * nace de una sustitución y el 04 de una factura global.
     *
     * @return list<self>
     */
    public static function direct(): array
    {
        return [self::ErrorsWithoutRelation, self::OperationNotCarriedOut];
    }

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
