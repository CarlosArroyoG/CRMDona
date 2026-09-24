<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\WritesMoneyAsNumbers;
use App\Models\Donation;
use App\Reports\AccountingControl;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exportación del Control contable con las columnas y el alcance de la
 * pantalla (cfdi.view). Sin RFC ni datos fiscales del donante.
 */
class AccountingControlExporter extends Exporter
{
    use FormatsExports;
    use WritesMoneyAsNumbers;

    protected static ?string $model = Donation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('receipt.folio')->label('Recibo simple'),
            ExportColumn::make('id')->label('Folio del donativo'),
            ExportColumn::make('received_on')->label('Fecha del donativo')->formatStateUsing(fn (mixed $state): string => self::date($state)),
            ExportColumn::make('donor.display_name')->label('Donante'),
            ExportColumn::make('amount')->label('Importe (MXN)'),
            ExportColumn::make('destination')->label('Destino')
                ->state(fn (Donation $record): string => $record->campaign->name ?? $record->program->name ?? 'Fondo general'),
            ExportColumn::make('tax_receipt_requested')->label('CFDI solicitado')
                ->state(fn (Donation $record): string => $record->tax_receipt_requested ? 'Sí' : 'No'),
            ExportColumn::make('notice_status')->label('Aviso a Contabilidad')
                ->state(fn (Donation $record): string => $record->accountingNotice?->status->getLabel() ?? 'Sin aviso'),
            ExportColumn::make('processing')->label('Procesamiento contable')
                ->state(fn (Donation $record): string => AccountingControl::isProcessed($record) ? 'Procesado' : 'Pendiente'),
            ExportColumn::make('external_cfdi')->label('CFDI externo adjunto')
                ->state(fn (Donation $record): string => AccountingControl::externalCfdi($record) !== null ? 'Sí' : 'No'),
            ExportColumn::make('external_cfdi_uuid')->label('UUID')
                ->state(fn (Donation $record): string => strtoupper((string) AccountingControl::externalCfdi($record)?->uuid)),
            ExportColumn::make('external_cfdi_issued_at')->label('Emisión del CFDI')
                ->state(fn (Donation $record): string => self::dateTime(AccountingControl::externalCfdi($record)?->issued_at)),
            ExportColumn::make('external_cfdi_uploaded_at')->label('CFDI adjuntado el')
                ->state(fn (Donation $record): string => self::dateTime(AccountingControl::externalCfdi($record)?->uploaded_at)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'donor', 'receipt', 'campaign.program', 'program', 'accountingNotice',
            'externalCfdis' => fn ($cfdis) => $cfdis->whereNull('removed_at'),
        ]);
    }

    protected static function moneyColumns(): array
    {
        return ['amount'];
    }
}
