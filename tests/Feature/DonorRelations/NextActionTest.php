<?php

declare(strict_types=1);

use App\Actions\Activities\CreateActivity;
use App\Actions\Tasks\CreateTask;
use App\DonorRelations\NextAction;
use App\Enums\ActivityType;
use App\Enums\Role;
use App\Models\Donor;

/*
 * Próxima acción: una sola fuente de verdad, derivada de la actividad
 * programada y la tarea abierta más próximas. No se guarda ningún campo en
 * `Donor` (docs/tecnico/gestion-relaciones-donantes.md).
 */

it('no hay próxima acción si el donante no tiene actividades programadas ni tareas abiertas', function (): void {
    $donor = Donor::factory()->create();

    expect(NextAction::for($donor))->toBeNull();
});

it('usa la actividad programada cuando es lo único pendiente', function (): void {
    $donor = Donor::factory()->create();
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    app(CreateActivity::class)->handle([
        'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'type' => ActivityType::Call->value,
        'subject' => 'Llamar para agradecer', 'status' => 'scheduled', 'scheduled_at' => now()->addDays(3)->toDateTimeString(),
    ], $coordinator);

    $next = NextAction::for($donor);

    expect($next?->title)->toBe('Llamar para agradecer');
});

it('usa la tarea abierta cuando es lo único pendiente', function (): void {
    $donor = Donor::factory()->create();
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    app(CreateTask::class)->handle([
        'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'title' => 'Enviar propuesta',
        'priority' => 'medium', 'due_date' => now()->addDays(5)->toDateString(),
    ], $coordinator);

    $next = NextAction::for($donor);

    expect($next?->title)->toBe('Enviar propuesta');
});

it('elige la más próxima entre una actividad programada y una tarea abierta', function (): void {
    $donor = Donor::factory()->create();
    $coordinator = userWithRole(Role::FundraisingCoordinator);

    app(CreateActivity::class)->handle([
        'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'type' => ActivityType::Visit->value,
        'subject' => 'Visitar en dos semanas', 'status' => 'scheduled', 'scheduled_at' => now()->addWeeks(2)->toDateTimeString(),
    ], $coordinator);
    app(CreateTask::class)->handle([
        'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'title' => 'Llamar mañana',
        'priority' => 'high', 'due_date' => now()->addDay()->toDateString(),
    ], $coordinator);

    expect(NextAction::for($donor)?->title)->toBe('Llamar mañana');
});

it('no considera tareas sin fecha límite ni actividades ya completadas o canceladas', function (): void {
    $donor = Donor::factory()->create();
    $coordinator = userWithRole(Role::FundraisingCoordinator);

    app(CreateTask::class)->handle([
        'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'title' => 'Tarea sin fecha', 'priority' => 'low',
    ], $coordinator);
    app(CreateActivity::class)->handle([
        'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'type' => ActivityType::Note->value,
        'subject' => 'Nota ya registrada', 'status' => 'completed',
    ], $coordinator);

    expect(NextAction::for($donor))->toBeNull();
});

it('ignora las actividades y tareas de otros donantes', function (): void {
    $donor = Donor::factory()->create();
    $other = Donor::factory()->create();
    $coordinator = userWithRole(Role::FundraisingCoordinator);

    app(CreateTask::class)->handle([
        'donor_id' => $other->id, 'assigned_to_id' => $coordinator->id, 'title' => 'Tarea de otro donante',
        'priority' => 'high', 'due_date' => now()->addDay()->toDateString(),
    ], $coordinator);

    expect(NextAction::for($donor))->toBeNull();
});
