<?php

declare(strict_types=1);

namespace App\Filament\Resources\CfdiReports;

use App\Enums\CfdiStatus;
use App\Enums\Permission;
use App\Filament\Exports\CfdiReportExporter;
use App\Filament\Resources\CfdiReports\Pages\ListCfdiReport;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\User;
use App\Reports\CfdiReport;
use App\Support\Money;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reporte CFDI: una fila por donativo confirmado (o con CFDI), con su CFDI
 * vigente, cancelaciones y ruta fiscal. Mismos permisos que la pantalla CFDI
 * (cfdi.view); el mensaje técnico del PAC solo con cfdi.view_technical.
 */
class CfdiReportResource extends Resource
{
    protected static ?string $model = Donation::class;

    protected static ?string $slug = 'reporte-cfdi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?string $modelLabel = 'donativo';

    protected static ?string $pluralModelLabel = 'Reporte CFDI';

    protected static ?string $navigationLabel = 'Reporte CFDI';

    protected static string|\UnitEnum|null $navigationGroup = 'Reportes';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return self::actor()?->hasPermission(Permission::ViewCfdis) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $technical = self::actor()?->hasPermission(Permission::ViewCfdiTechnicalDetails) ?? false;
        $report = app(CfdiReport::class);

        return $table
            ->query(CfdiReport::query())
            ->columns([
                TextColumn::make('received_on')->label('Fecha del donativo')->date('d/m/Y')->sortable(),
                TextColumn::make('donor.display_name')->label('Donante')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('donor', fn (Builder $donor): Builder => Search::unaccent($donor, 'display_name', $search))),
                TextColumn::make('amount')->label('Importe')->alignEnd()->sortable()->formatStateUsing(fn (string $state): string => Money::format($state)),
                TextColumn::make('cfdi_status')->label('CFDI')->badge()
                    ->state(fn (Donation $record): string => CfdiReport::activeCfdi($record)?->status->getLabel() ?? 'Sin CFDI vigente'),
                TextColumn::make('cfdi_uuid')->label('UUID')->placeholder('—')->toggleable()
                    ->state(fn (Donation $record): ?string => CfdiReport::activeCfdi($record)?->uuid),
                TextColumn::make('cfdi_stamped_at')->label('Timbrado')->placeholder('—')->toggleable()
                    ->state(fn (Donation $record): ?string => CfdiReport::activeCfdi($record)?->stamped_at?->format('d/m/Y H:i')),
                TextColumn::make('route')->label('Ruta fiscal')->badge()
                    ->state(fn (Donation $record) => $report->coverage($record)->route),
                TextColumn::make('reasons')->label('Bloqueo o error')->placeholder('—')->wrap()
                    ->state(fn (Donation $record): ?string => self::problem($record, $report, $technical)),
                TextColumn::make('cancellations')->label('Cancelaciones')->alignEnd()
                    ->state(fn (Donation $record): int => CfdiReport::cancelledCount($record)),
            ])
            ->defaultSort('received_on', 'desc')
            ->filters([
                Filter::make('received_on')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereDate('received_on', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereDate('received_on', '<=', $date))),
                SelectFilter::make('cfdi_status')->label('Estado del CFDI vigente')
                    ->options([CfdiReport::NO_CFDI => 'Sin CFDI vigente'] + collect(CfdiStatus::cases())
                        ->filter(fn (CfdiStatus $status): bool => $status->isActive())
                        ->mapWithKeys(fn (CfdiStatus $status): array => [$status->value => $status->getLabel()])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? CfdiReport::whereActiveStatus($query, (string) $data['value'])
                        : $query),
                TernaryFilter::make('public_general')->label('Público en general (sin datos fiscales)')
                    ->queries(
                        true: fn (Builder $query): Builder => CfdiReport::wherePublicGeneral($query, true),
                        false: fn (Builder $query): Builder => CfdiReport::wherePublicGeneral($query, false),
                    ),
                TernaryFilter::make('cancellations')->label('Con CFDI cancelados')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('cfdis', fn (Builder $cfdis) => $cfdis->where('status', CfdiStatus::Cancelled->value)),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('cfdis', fn (Builder $cfdis) => $cfdis->where('status', CfdiStatus::Cancelled->value)),
                    ),
                SelectFilter::make('donor_id')->label('Donante')->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Search::unaccent(Donor::query(), 'display_name', $search)
                        ->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => is_numeric($value) ? Donor::query()->whereKey((int) $value)->value('display_name') : null),
            ])
            ->headerActions([
                ExportAction::make()->label('Exportar')->exporter(CfdiReportExporter::class)
                    ->visible(fn (): bool => self::canViewAny()),
            ])
            ->recordUrl(fn (Donation $record): string => DonationResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Sin donativos que reportar');
    }

    /**
     * Motivo del bloqueo (sin CFDI) o del rechazo (CFDI vigente con error). El
     * mensaje técnico del PAC solo con cfdi.view_technical.
     */
    public static function problem(Donation $record, CfdiReport $report, bool $technical): ?string
    {
        $cfdi = CfdiReport::activeCfdi($record);
        if ($cfdi !== null) {
            if (! in_array($cfdi->status, [CfdiStatus::Rejected, CfdiStatus::Failed], true)) {
                return null;
            }

            return $technical ? ($cfdi->last_error ?? $cfdi->status->getLabel()) : $cfdi->status->getLabel().' (detalle para Administrador y Contador)';
        }

        $reasons = $report->coverage($record)->reasons;

        return $reasons === [] ? null : implode(' ', $reasons);
    }

    public static function getPages(): array
    {
        return ['index' => ListCfdiReport::route('/')];
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
