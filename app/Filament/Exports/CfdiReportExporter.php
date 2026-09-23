<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\WritesMoneyAsNumbers;
use App\Filament\Resources\CfdiReports\CfdiReportResource;
use App\Models\Donation;
use App\Reports\CfdiReport;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exportación del Reporte CFDI con las columnas y el alcance de la pantalla
 * (cfdi.view). Sin RFC ni datos fiscales del donante; el mensaje técnico del
 * PAC no se exporta.
 */
class CfdiReportExporter extends Exporter
{
    use FormatsExports;
    use WritesMoneyAsNumbers;

    protected static ?string $model = Donation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('Folio del donativo'),
            ExportColumn::make('received_on')->label('Fecha del donativo')->formatStateUsing(fn (mixed $state): string => self::date($state)),
            ExportColumn::make('donor.display_name')->label('Donante'),
            ExportColumn::make('amount')->label('Importe (MXN)'),
            ExportColumn::make('cfdi_status')->label('CFDI vigente')
                ->state(fn (Donation $record): string => CfdiReport::activeCfdi($record)?->status->getLabel() ?? 'Sin CFDI vigente'),
            ExportColumn::make('cfdi_uuid')->label('UUID')->state(fn (Donation $record): string => (string) CfdiReport::activeCfdi($record)?->uuid),
            ExportColumn::make('cfdi_stamped_at')->label('Timbrado el')
                ->state(fn (Donation $record): string => self::dateTime(CfdiReport::activeCfdi($record)?->stamped_at)),
            ExportColumn::make('route')->label('Ruta fiscal')
                ->state(fn (Donation $record): string => app(CfdiReport::class)->coverage($record)->route->getLabel()),
            ExportColumn::make('problem')->label('Bloqueo o error')
                ->state(fn (Donation $record): string => (string) CfdiReportResource::problem($record, app(CfdiReport::class), false)),
            ExportColumn::make('cancellations')->label('CFDI cancelados')->state(fn (Donation $record): int => CfdiReport::cancelledCount($record)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['donor.taxProfile', 'cfdis', 'payment', 'campaign.program', 'program']);
    }

    protected static function moneyColumns(): array
    {
        return ['amount'];
    }
}
