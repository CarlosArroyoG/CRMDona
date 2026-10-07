<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pendiente/En progreso → Hecha.
 */
class CompleteTask
{
    public const string NOT_OPEN = 'Solo se completan tareas pendientes o en progreso.';

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Task $task, User $actor): Task
    {
        if (! $actor->hasPermission(Permission::ManageTasks)) {
            throw new AuthorizationException('No tienes permiso para completar tareas.');
        }

        return DB::transaction(function () use ($task, $actor): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['task' => self::NOT_OPEN]);
            }

            $locked->auditAs(AuditEvent::Completed)->forceFill([
                'status' => TaskStatus::Done,
                'completed_at' => now(),
                'completed_by_id' => $actor->id,
            ])->save();

            return $locked;
        });
    }
}
