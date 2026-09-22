<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\DonationKind;
use App\Enums\DonationStatus;
use App\Enums\PaymentMethod;
use App\Filament\Exports\Concerns\WritesMoneyAsNumbers;
use App\Models\Donation;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Illuminate\Database\Eloquent\Builder;

class DonationExporter extends Exporter
{
    use FormatsExports;
    use WritesMoneyAsNumbers;

    protected static ?string $model = Donation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('Folio interno'),
            ExportColumn::make('received_on')->label('Fecha de recepción')
                ->formatStateUsing(fn (mixed $state): string => self::date($state)),
            ExportColumn::make('donor.display_name')->label('Donante'),
            ExportColumn::make('kind')->label('Tipo')
                ->formatStateUsing(fn (?DonationKind $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('payment_method')->label('Forma de pago')
                ->formatStateUsing(fn (?PaymentMethod $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('amount')->label('Importe o valor (MXN)'),
            ExportColumn::make('currency')->label('Moneda'),
            ExportColumn::make('status')->label('Estado')
                ->formatStateUsing(fn (?DonationStatus $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('campaign.name')->label('Campaña'),
            ExportColumn::make('effective_program')->label('Programa')
                ->state(fn (Donation $record): string => $record->effectiveProgram()->name ?? ''),
            ExportColumn::make('reference')->label('Referencia'),
            ExportColumn::make('in_kind_description')->label('Descripción de especie'),
            ExportColumn::make('tax_receipt_requested')->label('Solicitó recibo deducible')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No'),
            ExportColumn::make('registeredBy.name')->label('Registrado por'),
            ExportColumn::make('confirmed_at')->label('Confirmado el')
                ->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
            ExportColumn::make('confirmedBy.name')->label('Confirmado por'),
            ExportColumn::make('cancelled_at')->label('Cancelado el')
                ->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
            ExportColumn::make('cancellation_reason')->label('Motivo de cancelación'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['donor', 'campaign.program', 'program', 'registeredBy', 'confirmedBy']);
    }

    protected static function moneyColumns(): array
    {
        return ['amount'];
    }
}
