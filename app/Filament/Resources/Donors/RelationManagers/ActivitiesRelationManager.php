<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\RelationManagers;

use App\Actions\Activities\CreateActivity;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\Permission;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Actividades del donante (llamada, visita, WhatsApp informal…), con sus
 * acciones de completar, reprogramar y cancelar.
 */
class ActivitiesRelationManager extends RelationManager
{
    use ReportsActionErrors;
    use ResolvesActor;

    protected static string $relationship = 'activities';

    protected static ?string $title = 'Actividades';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorCan(Permission::ViewDonorActivities);
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
                TextColumn::make('type')->label('Tipo')->badge(),
                TextColumn::make('subject')->label('Asunto')->limit(40),
                TextColumn::make('assignedTo.name')->label('Responsable'),
                TextColumn::make('status')->label('Estado')->badge(),
                TextColumn::make('scheduled_at')->label('Programada')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('completed_at')->label('Completada')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(ActivityStatus::class),
                SelectFilter::make('type')->label('Tipo')->options(ActivityType::class),
            ])
            ->headerActions([$this->createAction()])
            ->recordActions([
                Action::make('open')->label('Ver')->icon(Heroicon::OutlinedEye)
                    ->url(fn (DonorActivity $record): string => ActivityResource::getUrl('view', ['record' => $record])),
                ActivityResource::completeAction(),
                ActivityResource::rescheduleAction(),
                ActivityResource::cancelAction(),
            ])
            ->emptyStateHeading('Este donante aún no tiene actividades registradas');
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('Nueva actividad')
            ->icon(Heroicon::OutlinedPlus)
            ->schema(ActivityResource::formFields(includeDonor: false))
            ->visible(fn (): bool => self::actorCan(Permission::ManageDonorActivities))
            ->action(function (array $data): void {
                /** @var Donor $donor */
                $donor = $this->getOwnerRecord();
                /** @var User $actor */
                $actor = auth()->user();
                self::notifyOutcome(
                    fn () => app(CreateActivity::class)->handle([...$data, 'donor_id' => $donor->id], $actor),
                    'Actividad registrada',
                );
            });
    }
}
