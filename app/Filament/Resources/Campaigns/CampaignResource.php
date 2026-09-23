<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns;

use App\Actions\Campaigns\DeleteCampaign;
use App\Enums\CampaignStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Exports\CampaignExporter;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\EditCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\Campaigns\Pages\ViewCampaign;
use App\Models\Campaign;
use App\Support\Money;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class CampaignResource extends Resource
{
    use ReportsActionErrors;

    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $modelLabel = 'campaña';

    protected static ?string $pluralModelLabel = 'campañas';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Destinos';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaña')
                ->description('Esfuerzo concreto de procuración, normalmente con fechas, por ejemplo "Navidad 2026".')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(150),
                    Select::make('program_id')->label('Programa')->relationship('program', 'name')
                        ->searchable()->preload()->placeholder('Sin programa')
                        ->helperText('Opcional. Si la campaña ya tiene donativos, no puede cambiar de programa.'),
                    Select::make('status')->label('Estado')->options(CampaignStatus::class)
                        ->default(CampaignStatus::Draft->value)->required()->native(false),
                    TextInput::make('goal_amount')->label('Meta (MXN)')->prefix('$')->inputMode('decimal')
                        ->helperText('Opcional. Ejemplo: 150000 o 150000.50'),
                    DatePicker::make('starts_on')->label('Fecha de inicio')->native(false)->displayFormat('d/m/Y'),
                    DatePicker::make('ends_on')->label('Fecha de fin')->native(false)->displayFormat('d/m/Y')
                        ->afterOrEqual('starts_on'),
                    TextInput::make('slug')->label('Identificador para enlaces')->maxLength(160)
                        ->helperText('Opcional. Se usará en la página pública futura; si lo dejas vacío se genera con el nombre.'),
                    Textarea::make('description')->label('Descripción')->rows(3)->columnSpanFull()->maxLength(5000),
                ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Campaña')->columns(2)->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('program.name')->label('Programa')->placeholder('Sin programa'),
                TextEntry::make('goal_amount')->label('Meta')->formatStateUsing(fn (?string $state): string => Money::format($state))->placeholder('Sin meta'),
                TextEntry::make('starts_on')->label('Inicio')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('ends_on')->label('Fin')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('slug')->label('Identificador'),
                TextEntry::make('public_url')->label('Página pública de donativos')->copyable()->columnSpanFull()
                    ->state(fn (Campaign $record): string => route('donate.campaign', ['campaign' => $record->slug]))
                    ->helperText(fn (Campaign $record): string => $record->acceptsDonations()
                        ? 'Recibe donativos: activa, dentro de sus fechas y con programa activo.'
                        : 'Por ahora NO recibe donativos (debe estar activa, dentro de sus fechas y con programa activo).'),
                TextEntry::make('description')->label('Descripción')->placeholder('Sin descripción')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => Search::unaccent($query, 'name', $search)),
                TextColumn::make('program.name')->label('Programa')->placeholder('—')->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('starts_on')->label('Inicio')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('ends_on')->label('Fin')->date('d/m/Y')->sortable()->placeholder('—'),
                TextColumn::make('goal_amount')->label('Meta')->alignEnd()
                    ->formatStateUsing(fn (?string $state): string => Money::format($state))->placeholder('—'),
            ])
            ->defaultSort('starts_on', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(CampaignStatus::class),
                SelectFilter::make('program_id')->label('Programa')->relationship('program', 'name')->preload(),
                Filter::make('dates')
                    ->schema([
                        DatePicker::make('from')->label('Vigente desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Vigente hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $from) => $q->where(fn (Builder $w) => $w->whereNull('ends_on')->orWhere('ends_on', '>=', $from)))
                        ->when($data['until'] ?? null, fn (Builder $q, string $until) => $q->where(fn (Builder $w) => $w->whereNull('starts_on')->orWhere('starts_on', '<=', $until))))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        isset($data['from']) ? 'Vigente desde '.date('d/m/Y', (int) strtotime($data['from'])) : null,
                        isset($data['until']) ? 'Vigente hasta '.date('d/m/Y', (int) strtotime($data['until'])) : null,
                    ]))),
            ])
            ->headerActions([
                ExportAction::make()->label('Exportar')->exporter(CampaignExporter::class)
                    ->visible(fn (): bool => Gate::allows('export', Campaign::class)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::deleteAction(),
            ])
            ->emptyStateHeading('Aún no hay campañas')
            ->emptyStateDescription('Crea la primera con el botón "Crear campaña".');
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription('Solo se puede eliminar una campaña sin donativos. Esta acción no se puede deshacer.')
            ->using(fn (Campaign $record): bool => self::notifyOutcome(
                fn () => app(DeleteCampaign::class)->handle($record),
                'Campaña eliminada',
            ))
            ->successNotification(null);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCampaigns::route('/'),
            'create' => CreateCampaign::route('/create'),
            'view' => ViewCampaign::route('/{record}'),
            'edit' => EditCampaign::route('/{record}/edit'),
        ];
    }
}
