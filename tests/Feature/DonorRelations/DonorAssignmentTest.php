<?php

declare(strict_types=1);

use App\Actions\DonorAssignments\AssignDonorResponsible;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\DonorAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travel;

it('asigna por primera vez al responsable de un donante', function (): void {
    $donor = Donor::factory()->create();
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    $admin = userWithRole(Role::Administrator);

    $assignment = app(AssignDonorResponsible::class)->handle($donor, $coordinator->id, 'Primer contacto', $admin);

    expect($assignment->user_id)->toBe($coordinator->id)
        ->and($assignment->assigned_by_id)->toBe($admin->id)
        ->and($assignment->ended_at)->toBeNull()
        ->and($donor->currentAssignment?->id)->toBe($assignment->id);

    $log = AuditLog::query()->where('auditable_type', 'donor_assignment')->where('auditable_id', $assignment->id)->sole();
    expect($log->event->value)->toBe('responsible_assigned');
});

it('reasignar cierra la asignación vigente y conserva el historial completo', function (): void {
    $donor = Donor::factory()->create();
    $first = userWithRole(Role::FundraisingCoordinator);
    $second = userWithRole(Role::Administrator);
    $admin = userWithRole(Role::Administrator);

    $firstAssignment = app(AssignDonorResponsible::class)->handle($donor, $first->id, null, $admin);
    travel(1)->minute();
    $secondAssignment = app(AssignDonorResponsible::class)->handle($donor, $second->id, 'Cambio de cartera', $admin);

    expect($firstAssignment->fresh()?->ended_at)->not->toBeNull()
        ->and($secondAssignment->ended_at)->toBeNull()
        ->and($donor->currentAssignment?->id)->toBe($secondAssignment->id)
        ->and(DonorAssignment::query()->where('donor_id', $donor->id)->count())->toBe(2)
        ->and($donor->assignmentHistory()->pluck('user_id')->all())->toBe([$second->id, $first->id]);
});

it('rechaza reasignar a quien ya es el responsable vigente', function (): void {
    $donor = Donor::factory()->create();
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    $admin = userWithRole(Role::Administrator);
    app(AssignDonorResponsible::class)->handle($donor, $coordinator->id, null, $admin);

    expect(fn () => app(AssignDonorResponsible::class)->handle($donor, $coordinator->id, null, $admin))
        ->toThrow(ValidationException::class);
});

it('solo Administrador o Coordinador pueden ser responsables asignados', function (): void {
    $donor = Donor::factory()->create();
    $accountant = userWithRole(Role::Accountant);
    $admin = userWithRole(Role::Administrator);

    expect(fn () => app(AssignDonorResponsible::class)->handle($donor, $accountant->id, null, $admin))
        ->toThrow(ValidationException::class);
});

it('solo Administrador y Coordinador pueden asignar responsable', function (Role $role, bool $allowed): void {
    $donor = Donor::factory()->create();
    $actor = userWithRole($role);
    $target = userWithRole(Role::FundraisingCoordinator);

    $attempt = fn () => app(AssignDonorResponsible::class)->handle($donor, $target->id, null, $actor);

    $allowed ? expect($attempt()->user_id)->toBe($target->id) : expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, true],
    'Contador' => [Role::Accountant, false],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('la base de datos impide dos responsables vigentes para el mismo donante', function (): void {
    $donor = Donor::factory()->create();
    $user = userWithRole(Role::FundraisingCoordinator);

    DonorAssignment::query()->create([
        'donor_id' => $donor->id, 'user_id' => $user->id, 'assigned_by_id' => $user->id, 'started_at' => now(),
    ]);

    expect(fn () => DonorAssignment::query()->create([
        'donor_id' => $donor->id, 'user_id' => $user->id, 'assigned_by_id' => $user->id, 'started_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('ficha del donante: Administrador y Coordinador pueden reasignar; Contador y Solo lectura no (Policy)', function (): void {
    $donor = Donor::factory()->create();

    expect(Gate::forUser(userWithRole(Role::Administrator))->allows('assignResponsible', $donor))->toBeTrue()
        ->and(Gate::forUser(userWithRole(Role::FundraisingCoordinator))->allows('assignResponsible', $donor))->toBeTrue()
        ->and(Gate::forUser(userWithRole(Role::Accountant))->allows('assignResponsible', $donor))->toBeFalse()
        ->and(Gate::forUser(userWithRole(Role::ReadOnly))->allows('assignResponsible', $donor))->toBeFalse();
});
