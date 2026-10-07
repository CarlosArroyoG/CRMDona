<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ActivityStatus;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Filament\Concerns\ResolvesActor;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\Task;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trabajo de relación con donantes: igual que "Trabajo del día"
 * (DailyWork), pero separado porque mide procuración, no pagos ni
 * contabilidad (docs/tecnico/gestion-relaciones-donantes.md). Solo cuenta lo
 * que ya puede verse filtrando sus propias pantallas.
 */
class DonorRelationsWork extends Widget
{
    use ResolvesActor;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.donor-relations-work';

    public static function canView(): bool
    {
        return self::actorCan(Permission::ViewTasks) || self::actorCan(Permission::ViewDonorActivities);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $actor = self::actor();

        $pending = array_values(array_filter([
            $actor !== null && self::actorCan(Permission::ViewTasks) ? [
                'label' => 'Mis tareas de hoy',
                'hint' => 'Asignadas a ti, con fecha límite hoy.',
                'count' => Task::query()->where('assigned_to_id', $actor->id)
                    ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value])
                    ->whereDate('due_date', now())->count(),
                'url' => TaskResource::getUrl(parameters: ['filters' => ['status' => ['value' => TaskStatus::Pending->value]]]),
            ] : null,
            $actor !== null && self::actorCan(Permission::ViewTasks) ? [
                'label' => 'Mis tareas vencidas',
                'hint' => 'Asignadas a ti, con fecha límite pasada.',
                'count' => Task::query()->where('assigned_to_id', $actor->id)
                    ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value])
                    ->whereDate('due_date', '<', now())->count(),
                'url' => TaskResource::getUrl(),
            ] : null,
            $actor !== null && self::actorCan(Permission::ViewDonorActivities) ? [
                'label' => 'Mis seguimientos próximos',
                'hint' => 'Actividades programadas a tu nombre en los próximos 7 días.',
                'count' => DonorActivity::query()->where('assigned_to_id', $actor->id)
                    ->where('status', ActivityStatus::Scheduled->value)
                    ->whereBetween('scheduled_at', [now(), now()->addDays(7)])->count(),
                'url' => ActivityResource::getUrl(),
            ] : null,
            self::actorCan(Permission::ViewDonorActivities) ? [
                'label' => 'Donantes sin próxima acción',
                'hint' => 'Activos, sin actividad programada ni tarea abierta.',
                'count' => Donor::query()->whereNull('archived_at')
                    ->whereDoesntHave('activities', fn (Builder $query): Builder => $query->where('status', ActivityStatus::Scheduled->value))
                    ->whereDoesntHave('tasks', fn (Builder $query): Builder => $query->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value]))
                    ->count(),
                'url' => null,
            ] : null,
        ]));

        return ['pending' => $pending];
    }
}
