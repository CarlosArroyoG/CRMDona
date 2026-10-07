<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\RelationManagers;

use App\Actions\Tasks\CreateTask;
use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Donor;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Tareas ligadas a este donante (prioridad y fecha límite), con sus
 * acciones de completar y cancelar.
 */
class TasksRelationManager extends RelationManager
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tareas';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorCan(Permission::ViewTasks);
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('assignedTo'))
            ->columns([
                TextColumn::make('title')->label('Título')->limit(40),
                TextColumn::make('assignedTo.name')->label('Responsable'),
                TextColumn::make('priority')->label('Prioridad')->badge(),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('due_date')->label('Fecha límite')->date('d/m/Y')->placeholder('—'),
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(TaskStatus::class),
                SelectFilter::make('priority')->label('Prioridad')->options(TaskPriority::class),
            ])
            ->headerActions([$this->createAction()])
            ->recordActions([
                Action::make('open')->label('Ver')->icon(Heroicon::OutlinedEye)
                    ->url(fn (Task $record): string => TaskResource::getUrl('view', ['record' => $record])),
                TaskResource::completeAction(),
                TaskResource::cancelAction(),
            ])
            ->emptyStateHeading('Este donante aún no tiene tareas');
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('Nueva tarea')
            ->icon(Heroicon::OutlinedPlus)
            ->schema(TaskResource::formFields(includeDonor: false))
            ->visible(fn (): bool => self::actorCan(Permission::ManageTasks))
            ->action(function (array $data): void {
                /** @var Donor $donor */
                $donor = $this->getOwnerRecord();
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(
                    fn () => app(CreateTask::class)->handle([...$data, 'donor_id' => $donor->id], $actor),
                    'Tarea creada',
                );
            });
    }
}
