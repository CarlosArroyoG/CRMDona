<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\ProgramStatus;
use App\Models\Program;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;

class ProgramExporter extends Exporter
{
    use FormatsExports;

    protected static ?string $model = Program::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name')->label('Nombre'),
            ExportColumn::make('slug')->label('Identificador'),
            ExportColumn::make('status')->label('Estado')
                ->formatStateUsing(fn (?ProgramStatus $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('description')->label('Descripción'),
            ExportColumn::make('campaigns_count')->label('Campañas')->counts('campaigns'),
            ExportColumn::make('created_at')->label('Creado')
                ->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
        ];
    }
}
