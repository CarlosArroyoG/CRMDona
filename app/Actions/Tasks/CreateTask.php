<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Crea una tarea. `donor_id` es opcional: no toda tarea del Coordinador o del
 * Contador es sobre un donante concreto.
 */
class CreateTask
{
    use NormalizesInput;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $input, User $actor): Task
    {
        if (! $actor->hasPermission(Permission::ManageTasks)) {
            throw new AuthorizationException('No tienes permiso para crear tareas.');
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

        return Task::query()->create([
            'donor_id' => isset($data['donor_id']) ? (int) $data['donor_id'] : null,
            'assigned_to_id' => (int) $data['assigned_to_id'],
            'created_by_id' => $actor->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'],
            'due_date' => $data['due_date'] ?? null,
            'status' => TaskStatus::Pending,
        ]);
    }
}
