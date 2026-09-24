<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Enums\DonorType;
use App\Enums\TaxRegime;
use App\Models\Donor;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Incluye datos fiscales: solo pueden exportar donantes los roles que ya
 * tienen acceso a ellos (Solo lectura no exporta donantes).
 */
class DonorExporter extends Exporter
{
    use FormatsExports;

    protected static ?string $model = Donor::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('type')->label('Tipo')
                ->formatStateUsing(fn (?DonorType $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('display_name')->label('Nombre o razón social'),
            ExportColumn::make('first_name')->label('Nombre(s)'),
            ExportColumn::make('last_name')->label('Apellido paterno'),
            ExportColumn::make('second_last_name')->label('Apellido materno'),
            ExportColumn::make('legal_name')->label('Razón social'),
            ExportColumn::make('contact_name')->label('Persona de contacto'),
            ExportColumn::make('email')->label('Correo electrónico'),
            // Validado como solo dígitos, espacios y + ( ) -: nunca forma una fórmula; se exporta sin apóstrofo.
            ExportColumn::make('phone')->label('Teléfono')->preventFormulaInjection(false),
            ExportColumn::make('birth_date')->label('Fecha de nacimiento')
                ->formatStateUsing(fn (mixed $state): string => self::date($state)),
            ExportColumn::make('tags_list')->label('Etiquetas')
                ->state(fn (Donor $record): string => $record->tags->pluck('name')->sort()->implode(', ')),
            ExportColumn::make('accepts_communications')->label('Acepta comunicaciones')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Sí' : 'No'),
            ExportColumn::make('privacy_notice_version')->label('Versión del aviso aceptada'),
            ExportColumn::make('privacy_notice_accepted_at')->label('Fecha de aceptación del aviso')
                ->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
            ExportColumn::make('taxProfile.rfc')->label('RFC'),
            ExportColumn::make('taxProfile.tax_name')->label('Nombre fiscal'),
            ExportColumn::make('taxProfile.tax_regime')->label('Régimen fiscal')
                ->formatStateUsing(fn (?TaxRegime $state): string => $state?->getLabel() ?? ''),
            ExportColumn::make('taxProfile.tax_postal_code')->label('CP fiscal'),
            ExportColumn::make('archived_at')->label('Archivado')
                ->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
            ExportColumn::make('created_at')->label('Registrado')
                ->formatStateUsing(fn (mixed $state): string => self::dateTime($state)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['tags', 'taxProfile']);
    }
}
