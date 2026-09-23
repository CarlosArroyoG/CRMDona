<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RefundState;
use App\Filament\Exports\Concerns\WritesMoneyAsNumbers;
use App\Models\Payment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exportación operativa de pagos (Administrador, Coordinador y Contador).
 * Sin identificadores del proveedor, códigos ni payloads.
 */
class PaymentExporter extends Exporter
{
    use FormatsExports;
    use WritesMoneyAsNumbers;

    protected static ?string $model = Payment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('Folio interno'),
            ExportColumn::make('created_at')->label('Fecha')->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
            ExportColumn::make('donor.display_name')->label('Donante'),
            ExportColumn::make('amount')->label('Importe (MXN)'),
            ExportColumn::make('currency')->label('Moneda'),
            ExportColumn::make('provider')->label('Proveedor')
                ->formatStateUsing(fn (?PaymentProvider $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('kind')->label('Único o mensual')
                ->formatStateUsing(fn (?PaymentKind $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('status')->label('Estado')
                ->formatStateUsing(fn (?PaymentStatus $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('refunded_amount')->label('Reembolsado (MXN)')
                ->state(fn (Payment $record): string => $record->refundedAmount()),
            ExportColumn::make('refund_state')->label('Reembolso')
                ->state(fn (Payment $record): string => RefundState::fromAmounts($record->amount, $record->refundedAmount())->getLabel()),
            ExportColumn::make('campaign.name')->label('Campaña'),
            ExportColumn::make('effective_program')->label('Programa')
                ->state(fn (Payment $record): string => $record->effectiveProgram()->name ?? ''),
            ExportColumn::make('succeeded_at')->label('Cobrado el')->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
            ExportColumn::make('donation.id')->label('Folio del donativo'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['donor', 'campaign.program', 'program', 'donation']);
    }

    protected static function moneyColumns(): array
    {
        return ['amount', 'refunded_amount'];
    }
}
