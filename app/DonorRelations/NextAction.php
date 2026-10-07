<?php

declare(strict_types=1);

namespace App\DonorRelations;

use App\DonorRelations\Data\NextActionDto;
use App\Enums\ActivityStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\Task;

/**
 * Próxima acción de un donante: una sola fuente de verdad, derivada en
 * consulta (no se guarda ningún campo "next_action" en `Donor`). Es la más
 * próxima entre su actividad programada más cercana y su tarea abierta con
 * fecha límite más próxima (docs/tecnico/gestion-relaciones-donantes.md).
 */
final class NextAction
{
    public static function for(Donor $donor): ?NextActionDto
    {
        $candidates = array_filter([
            self::fromActivity($donor),
            self::fromTask($donor),
        ]);

        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (NextActionDto $a, NextActionDto $b): int => $a->dueAt->getTimestamp() <=> $b->dueAt->getTimestamp());

        return $candidates[0];
    }

    private static function fromActivity(Donor $donor): ?NextActionDto
    {
        $activity = DonorActivity::query()
            ->where('donor_id', $donor->id)
            ->where('status', ActivityStatus::Scheduled->value)
            ->whereNotNull('scheduled_at')
            ->orderBy('scheduled_at')
            ->first();

        if ($activity === null || $activity->scheduled_at === null) {
            return null;
        }

        return new NextActionDto(
            title: $activity->subject,
            dueAt: $activity->scheduled_at,
            url: ActivityResource::getUrl('view', ['record' => $activity->id]),
        );
    }

    private static function fromTask(Donor $donor): ?NextActionDto
    {
        $task = Task::query()
            ->where('donor_id', $donor->id)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value])
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->first();

        if ($task === null || $task->due_date === null) {
            return null;
        }

        return new NextActionDto(
            title: $task->title,
            dueAt: $task->due_date,
            url: TaskResource::getUrl('view', ['record' => $task->id]),
        );
    }
}
