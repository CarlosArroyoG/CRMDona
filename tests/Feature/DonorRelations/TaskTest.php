<?php

declare(strict_types=1);

use App\Actions\Tasks\CancelTask;
use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\CreateTask;
use App\Enums\Role;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 */
function createTestTask(array $overrides = [], ?User $actor = null): Task
{
    return app(CreateTask::class)->handle([
        'assigned_to_id' => userWithRole(Role::FundraisingCoordinator)->id,
        'title' => 'Preparar propuesta de donativo',
        'priority' => TaskPriority::Medium->value,
        'due_date' => now()->addWeek()->toDateString(),
        ...$overrides,
    ], $actor ?? userWithRole(Role::FundraisingCoordinator));
}

it('crea una tarea ligada a un donante', function (): void {
    $donor = Donor::factory()->create();
    $task = createTestTask(['donor_id' => $donor->id]);

    expect($task->donor_id)->toBe($donor->id)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->completed_at)->toBeNull();
});

it('crea una tarea general sin donante', function (): void {
    $task = createTestTask();

    expect($task->donor_id)->toBeNull()
        ->and($task->status)->toBe(TaskStatus::Pending);

    expect(AuditLog::query()->where('auditable_type', 'task')->where('auditable_id', $task->id)->where('event', 'created')->exists())->toBeTrue();
});

it('completa una tarea pendiente o en progreso', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $task = createTestTask(actor: $actor);

    $completed = app(CompleteTask::class)->handle($task, $actor);

    expect($completed->status)->toBe(TaskStatus::Done)
        ->and($completed->completed_by_id)->toBe($actor->id)
        ->and($completed->completed_at)->not->toBeNull();
});

it('cancela una tarea abierta; el historial no se borra', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $task = createTestTask(actor: $actor);

    $cancelled = app(CancelTask::class)->handle($task, $actor);

    expect($cancelled->status)->toBe(TaskStatus::Cancelled)
        ->and($cancelled->cancelled_by_id)->toBe($actor->id)
        ->and(Task::query()->count())->toBe(1);
});

it('no completa ni cancela una tarea que ya está hecha o cancelada', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $task = createTestTask(actor: $actor);
    app(CompleteTask::class)->handle($task, $actor);

    expect(fn () => app(CompleteTask::class)->handle($task, $actor))->toThrow(ValidationException::class)
        ->and(fn () => app(CancelTask::class)->handle($task, $actor))->toThrow(ValidationException::class);
});

it('Administrador, Coordinador y Contador crean y gestionan tareas; Solo lectura no', function (Role $role, bool $allowed): void {
    $user = userWithRole($role);
    $attempt = fn () => createTestTask(actor: $user);

    if ($allowed) {
        expect($attempt()->status)->toBe(TaskStatus::Pending);
    } else {
        expect($attempt)->toThrow(AuthorizationException::class);
    }
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, true],
    'Contador' => [Role::Accountant, true],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('revalida el permiso dentro de las Actions aunque no se pase por Filament', function (): void {
    $readOnly = userWithRole(Role::ReadOnly);
    $task = createTestTask();

    expect(fn () => app(CompleteTask::class)->handle($task, $readOnly))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelTask::class)->handle($task, $readOnly))->toThrow(AuthorizationException::class);
});

it('los cuatro roles ven tareas, pero Solo lectura no las gestiona (Policy)', function (): void {
    $task = createTestTask();

    foreach ([Role::Administrator, Role::FundraisingCoordinator, Role::Accountant, Role::ReadOnly] as $role) {
        expect(Gate::forUser(userWithRole($role))->allows('view', $task))->toBeTrue();
    }

    expect(Gate::forUser(userWithRole(Role::Accountant))->allows('complete', $task))->toBeTrue()
        ->and(Gate::forUser(userWithRole(Role::ReadOnly))->allows('complete', $task))->toBeFalse();
});
