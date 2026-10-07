<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks;

use App\Actions\Tasks\CancelTask;
use App\Actions\Tasks\CompleteTask;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Donors\DonorResource;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\Donor;
use App\Models\Task;
use App\Models\User;
use App\Support\Search;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Tareas: pendientes accionables con prioridad y fecha límite. No siempre
 * son de un donante (docs/tecnico/gestion-relaciones-donantes.md).
 */
class TaskResource extends Resource
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?string $modelLabel = 'tarea';

    protected static ?string $pluralModelLabel = 'tareas';

    protected static string|\UnitEnum|null $navigationGroup = 'Recaudación';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tarea')->columns(2)->schema(self::formFields(includeDonor: true)),
        ]);
    }

    /**
     * Campos compartidos por el formulario del recurso y por "Nueva tarea"
     * en la ficha del donante (donde el donante ya está implícito).
     *
     * @return list<Component>
     */
    public static function formFields(bool $includeDonor): array
    {
        return array_values(array_filter([
            $includeDonor ? Select::make('donor_id')->label('Donante')->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Search::unaccent(Donor::query()->whereNull('archived_at'), 'display_name', $search)
                    ->orderBy('display_name')->limit(20)->pluck('display_name', 'id')->all())
                ->getOptionLabelUsing(fn (mixed $value): ?string => self::donorLabel($value))
                ->helperText('Déjalo vacío si la tarea no es sobre un donante en particular.') : null,
            Select::make('assigned_to_id')->label('Responsable')->required()
                ->options(fn (): array => User::query()->whereNotNull('role')->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            TextInput::make('title')->label('Título')->required()->maxLength(255)->columnSpanFull(),
            Textarea::make('description')->label('Descripción')->rows(3)->maxLength(2000)->columnSpanFull(),
            Select::make('priority')->label('Prioridad')->options(TaskPriority::class)
                ->default(TaskPriority::Medium->value)->required(),
            DatePicker::make('due_date')->label('Fecha límite')->native(false)->displayFormat('d/m/Y'),
        ]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tarea')->columns(3)->schema([
                TextEntry::make('donor.display_name')->label('Donante')->placeholder('—')
                    ->url(fn (Task $record): ?string => $record->donor_id !== null
                        ? DonorResource::getUrl('view', ['record' => $record->donor_id]) : null),
                TextEntry::make('priority')->label('Prioridad')->badge(),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('assignedTo.name')->label('Responsable'),
                TextEntry::make('createdBy.name')->label('Creada por'),
                TextEntry::make('due_date')->label('Fecha límite')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('title')->label('Título')->columnSpanFull(),
                TextEntry::make('description')->label('Descripción')->placeholder('—')->columnSpanFull(),
                TextEntry::make('completed_at')->label('Completada el')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('completedBy.name')->label('Completada por')->placeholder('—'),
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
                TextColumn::make('title')->label('Título')->limit(40)->searchable(),
                TextColumn::make('donor.display_name')->label('Donante')->placeholder('—')->sortable(),
                TextColumn::make('assignedTo.name')->label('Responsable')->sortable(),
                TextColumn::make('priority')->label('Prioridad')->badge()->sortable(),
                TextColumn::make('status')->label('Estado')->badge()->sortable(),
                TextColumn::make('due_date')->label('Fecha límite')->date('d/m/Y')->placeholder('—')->sortable(),
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(TaskStatus::class),
                SelectFilter::make('priority')->label('Prioridad')->options(TaskPriority::class),
                SelectFilter::make('assigned_to_id')->label('Responsable')
                    ->options(fn (): array => User::query()->whereNotNull('role')->orderBy('name')->pluck('name', 'id')->all()),
                TernaryFilter::make('donor_id')->label('Ligada a un donante')
                    ->trueLabel('Ligadas a un donante')->falseLabel('Generales')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('donor_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('donor_id'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::completeAction(),
                self::cancelAction(),
            ])
            ->emptyStateHeading('No hay tareas que mostrar')
            ->emptyStateDescription('Crea una desde "Nueva tarea" o desde la ficha del donante.');
    }

    public static function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Completar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (Task $record): bool => Gate::allows('complete', $record))
            ->action(function (Task $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(CompleteTask::class)->handle($record, $actor), 'Tarea completada');
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('La tarea no se borra: queda en el historial como "Cancelada".')
            ->visible(fn (Task $record): bool => Gate::allows('cancel', $record))
            ->action(function (Task $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(fn () => app(CancelTask::class)->handle($record, $actor), 'Tarea cancelada');
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'create' => CreateTask::route('/create'),
            'view' => ViewTask::route('/{record}'),
            'edit' => EditTask::route('/{record}/edit'),
        ];
    }

    private static function donorLabel(mixed $value): ?string
    {
        $name = is_numeric($value) ? Donor::query()->whereKey((int) $value)->value('display_name') : null;

        return is_string($name) ? $name : null;
    }
}
