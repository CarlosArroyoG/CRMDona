<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\CampaignStatus;
use App\Filament\Exports\Concerns\WritesMoneyAsNumbers;
use App\Models\Campaign;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;

class CampaignExporter extends Exporter
{
    use FormatsExports;
    use WritesMoneyAsNumbers;

    protected static ?string $model = Campaign::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name')->label('Nombre'),
            ExportColumn::make('program.name')->label('Programa'),
            ExportColumn::make('slug')->label('Identificador'),
            ExportColumn::make('status')->label('Estado')
                ->formatStateUsing(fn (?CampaignStatus $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('starts_on')->label('Inicio')
                ->formatStateUsing(fn (mixed $state): string => self::date($state)),
            ExportColumn::make('ends_on')->label('Fin')
                ->formatStateUsing(fn (mixed $state): string => self::date($state)),
            ExportColumn::make('goal_amount')->label('Meta (MXN)'),
            ExportColumn::make('description')->label('Descripción'),
        ];
    }

    protected static function moneyColumns(): array
    {
        return ['goal_amount'];
    }
}
