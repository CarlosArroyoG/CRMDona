<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ActivityStatus;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Donors\DonorResource;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\Task;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Cartera asignada: los donantes de los que el usuario es responsable
 * vigente (docs/tecnico/gestion-relaciones-donantes.md). La próxima acción
 * se trae con subconsultas correlacionadas en la misma query (sin N+1): una
 * fecha por fila, no una llamada a NextAction por donante.
 */
class MyPortfolio extends TableWidget
{
    use ResolvesActor;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::actorCan(Permission::ViewDonorActivities) && self::actor() !== null;
    }

    public function table(Table $table): Table
    {
        $actor = self::actor();

        return $table
            ->heading('Mi cartera de donantes')
            ->query(Donor::query()
                ->whereHas('currentAssignment', fn (Builder $query): Builder => $query->where('user_id', $actor?->id))
                ->whereNull('archived_at')
                ->addSelect([
                    'next_activity_at' => DonorActivity::query()->selectRaw('min(scheduled_at)')
                        ->whereColumn('donor_id', 'donors.id')
                        ->where('status', ActivityStatus::Scheduled->value),
                    'next_task_due' => Task::query()->selectRaw('min(due_date)')
                        ->whereColumn('donor_id', 'donors.id')
                        ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value]),
                ]))
            ->columns([
                TextColumn::make('display_name')->label('Donante')
                    ->url(fn (Donor $record): string => DonorResource::getUrl('view', ['record' => $record])),
                TextColumn::make('next_action')->label('Próxima acción')
                    ->state(function (Donor $record): string {
                        $dates = array_filter([$record->getAttribute('next_activity_at'), $record->getAttribute('next_task_due')]);

                        if ($dates === []) {
                            return 'Sin próxima acción pendiente';
                        }

                        sort($dates);

                        return Carbon::parse($dates[0])->format('d/m/Y');
                    }),
            ])
            ->defaultSort('display_name')
            ->emptyStateHeading('No tienes donantes asignados todavía');
    }
}
