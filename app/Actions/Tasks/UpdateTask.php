<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Models\Donor;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Solo una tarea abierta (pendiente o en progreso) cambia sus datos
 * generales. Completarla o cancelarla son Actions propias.
 */
class UpdateTask
{
    use NormalizesInput;

    public const string NOT_OPEN = 'Solo se modifican tareas pendientes o en progreso.';

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Task $task, array $input, User $actor): Task
    {
        if (! $actor->hasPermission(Permission::ManageTasks)) {
            throw new AuthorizationException('No tienes permiso para modificar tareas.');
        }

        $input = $this->normalize($input);
        $data = Validator::make($input, [
            'donor_id' => ['nullable', 'integer', Rule::exists(Donor::class, 'id')],
            'assigned_to_id' => ['required', 'integer', Rule::exists(User::class, 'id')],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['required', new Enum(TaskPriority::class)],
            'due_date' => ['nullable', 'date'],
        ], [], [
            'donor_id' => 'donante', 'assigned_to_id' => 'responsable', 'title' => 'título', 'due_date' => 'fecha límite',
        ])->validate();

        $data['donor_id'] = isset($data['donor_id']) ? (int) $data['donor_id'] : null;

        return DB::transaction(function () use ($task, $data): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['task' => self::NOT_OPEN]);
            }

            $locked->fill($data)->save();

            return $locked;
        });
    }
}
