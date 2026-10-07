<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities;

use App\Actions\Activities\CancelActivity;
use App\Actions\Activities\CompleteActivity;
use App\Actions\Activities\RescheduleActivity;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Activities\Pages\CreateActivity;
use App\Filament\Resources\Activities\Pages\EditActivity;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Resources\Donors\DonorResource;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\User;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Actividades de relación con donantes: interacciones registradas a mano
 * (llamada, visita, WhatsApp informal…). No sustituye `communications`
 * (docs/tecnico/gestion-relaciones-donantes.md).
 */
class ActivityResource extends Resource
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static ?string $model = DonorActivity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'actividad';

    protected static ?string $pluralModelLabel = 'actividades';

    protected static string|\UnitEnum|null $navigationGroup = 'Recaudación';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Actividad')->columns(2)->schema(self::formFields(includeDonor: true)),
        ]);
    }

    /**
     * Campos compartidos por el formulario del recurso y por "Nueva
     * actividad" en la ficha del donante (donde el donante ya está
     * implícito y no se vuelve a preguntar).
     *
     * @return list<Component>
     */
    public static function formFields(bool $includeDonor): array
    {
        $isCreate = fn (string $operation): bool => $operation === 'create';
        $isScheduled = fn (Get $get): bool => $get('status') === ActivityStatus::Scheduled->value;

        return array_values(array_filter([
            $includeDonor ? Select::make('donor_id')->label('Donante')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Search::unaccent(Donor::query()->whereNull('archived_at'), 'display_name', $search)
                    ->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::donorLabel($value)) : null,
            Select::make('assigned_to_id')->label('Responsable del seguimiento')->required()
                ->options(fn (): array => User::query()->whereNotNull('role')->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('type')->label('Tipo')->options(ActivityType::class)->required(),
            TextInput::make('subject')->label('Asunto')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->label('Descripción')->rows(3)->maxLength(2000)->columnSpanFull(),
            Radio::make('status')->label('¿Cuándo ocurre?')
                ->options([
                    ActivityStatus::Scheduled->value => 'Se va a hacer (programar fecha)',
                    ActivityStatus::Completed->value => 'Ya ocurrió',
                ])
                ->default(ActivityStatus::Scheduled->value)->inline()->live()->required()
                ->visible($isCreate),
            DateTimePicker::make('scheduled_at')->label('Fecha y hora programada')->required()->native(false)
                ->displayFormat('d/m/Y H:i')
                ->visible(fn (Get $get, string $operation): bool => $isCreate($operation) && $isScheduled($get)),
            Textarea::make('result')->label('Resultado')->rows(3)->maxLength(2000)->columnSpanFull()
                ->visible(fn (Get $get, string $operation): bool => $isCreate($operation) && ! $isScheduled($get)),
            Textarea::make('next_action')->label('Próxima acción sugerida')->rows(2)->maxLength(1000)->columnSpanFull()
                ->helperText('Nota libre: no crea una tarea automáticamente. Crea una tarea aparte si hace falta dar seguimiento con fecha límite.'),
        ]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Actividad')->columns(3)->schema([
                TextEntry::make('donor.display_name')->label('Donante')
                    ->url(fn (DonorActivity $record): string => DonorResource::getUrl('view', ['record' => $record->donor_id])),
                TextEntry::make('type')->label('Tipo')->badge(),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('assignedTo.name')->label('Responsable'),
                TextEntry::make('createdBy.name')->label('Registrada por'),
                TextEntry::make('created_at')->label('Registrada el')->dateTime('d/m/Y H:i'),
                TextEntry::make('subject')->label('Asunto')->columnSpanFull(),
                TextEntry::make('description')->label('Descripción')->placeholder('—')->columnSpanFull(),
                TextEntry::make('scheduled_at')->label('Programada para')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('completed_at')->label('Completada el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('completedBy.name')->label('Completada por')->placeholder('—'),
                TextEntry::make('result')->label('Resultado')->placeholder('—')->columnSpanFull(),
                TextEntry::make('next_action')->label('Próxima acción sugerida')->placeholder('—')->columnSpanFull(),
                TextEntry::make('cancelled_at')->label('Cancelada el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('cancelledBy.name')->label('Cancelada por')->placeholder('—'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['donor', 'assignedTo']))
            ->columns([
                TextColumn::make('donor.display_name')->label('Donante')->sortable()->searchable(),
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('subject')->label('Asunto')->limit(40),
                TextColumn::make('assignedTo.name')->label('Responsable')->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('scheduled_at')->label('Programada')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
                TextColumn::make('completed_at')->label('Completada')->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ActivityStatus::class),
                SelectFilter::make('type')->label('Tipo')->options(ActivityType::class),
                SelectFilter::make('assigned_to_id')->label('Responsable')
                    ->options(fn (): array => User::query()->whereNotNull('role')->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::completeAction(),
                self::rescheduleAction(),
                self::cancelAction(),
            ])
            ->emptyStateHeading('No hay actividades que mostrar')
            ->emptyStateDescription('Registra una desde "Nueva actividad" o desde la ficha del donante.');
    }

    public static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Completar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->schema([
                Textarea::make('result')->label('Resultado')->maxLength(2000),
                Textarea::make('next_action')->label('Próxima acción sugerida')->maxLength(1000)
                    ->helperText('Nota libre: no crea una tarea automáticamente.'),
            ])
            ->visible(fn (DonorActivity $record): bool => Gate::allows('complete', $record))
            ->action(function (DonorActivity $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(CompleteActivity::class)->handle($record, $data, $actor), 'Actividad completada');
            });
    }

    public static function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->label('Reprogramar')
            ->icon(Heroicon::OutlinedCalendar)
            ->color('gray')
            ->schema([
                DateTimePicker::make('scheduled_at')->label('Nueva fecha y hora')->required()->native(false)
                    ->displayFormat('d/m/Y H:i'),
            ])
            ->fillForm(fn (DonorActivity $record): array => ['scheduled_at' => $record->scheduled_at])
            ->visible(fn (DonorActivity $record): bool => Gate::allows('reschedule', $record))
            ->action(function (DonorActivity $record, array $data): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(
                    fn () => app(RescheduleActivity::class)->handle($record, (string) $data['scheduled_at'], $actor),
                    'Actividad reprogramada',
                );
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('La actividad no se borra: queda en el historial como "Cancelada".')
            ->visible(fn (DonorActivity $record): bool => Gate::allows('cancel', $record))
            ->action(function (DonorActivity $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(CancelActivity::class)->handle($record, $actor), 'Actividad cancelada');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'create' => CreateActivity::route('/create'),
            'view' => ViewActivity::route('/{record}'),
            'edit' => EditActivity::route('/{record}/edit'),
        ];
    }

    private static function donorLabel(mixed $value): ?string
    {
        $name = is_numeric($value) ? Donor::query()->whereKey((int) $value)->value('display_name') : null;

        return is_string($name) ? $name : null;
    }
}
