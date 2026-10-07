<?php

declare(strict_types=1);

use App\Actions\Activities\CancelActivity;
use App\Actions\Activities\CompleteActivity;
use App\Actions\Activities\CreateActivity;
use App\Actions\Activities\RescheduleActivity;
use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\AuditSource;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\DonorActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 */
function createTestActivity(array $overrides = [], ?User $actor = null): DonorActivity
{
    return app(CreateActivity::class)->handle([
        'donor_id' => Donor::factory()->create()->id,
        'assigned_to_id' => userWithRole(Role::FundraisingCoordinator)->id,
        'type' => ActivityType::Call->value,
        'subject' => 'Llamada de seguimiento',
        'status' => ActivityStatus::Scheduled->value,
        'scheduled_at' => now()->addDay()->toDateTimeString(),
        ...$overrides,
    ], $actor ?? userWithRole(Role::FundraisingCoordinator));
}

it('registra una actividad programada', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    Auth::login($actor);
    $activity = createTestActivity(actor: $actor);

    expect($activity->status)->toBe(ActivityStatus::Scheduled)
        ->and($activity->scheduled_at)->not->toBeNull()
        ->and($activity->completed_at)->toBeNull()
        ->and($activity->created_by_id)->toBe($actor->id);

    $log = AuditLog::query()->where('auditable_type', 'donor_activity')->where('auditable_id', $activity->id)->sole();
    expect($log->event->value)->toBe('created')->and($log->source)->toBe(AuditSource::User);
});

it('registra una actividad ya ocurrida directamente como completada', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $activity = createTestActivity([
        'status' => ActivityStatus::Completed->value,
        'scheduled_at' => null,
        'result' => 'El donante confirmó su donativo mensual.',
    ], $actor);

    expect($activity->status)->toBe(ActivityStatus::Completed)
        ->and($activity->completed_at)->not->toBeNull()
        ->and($activity->completed_by_id)->toBe($actor->id)
        ->and($activity->result)->toBe('El donante confirmó su donativo mensual.');
});

it('completa una actividad programada y registra resultado y próxima acción', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    Auth::login($actor);
    $activity = createTestActivity(actor: $actor);

    $completed = app(CompleteActivity::class)->handle($activity, [
        'result' => 'Aceptó recibir la propuesta por correo.',
        'next_action' => 'Enviar propuesta la próxima semana.',
    ], $actor);

    expect($completed->status)->toBe(ActivityStatus::Completed)
        ->and($completed->completed_by_id)->toBe($actor->id)
        ->and($completed->result)->toBe('Aceptó recibir la propuesta por correo.')
        ->and($completed->next_action)->toBe('Enviar propuesta la próxima semana.');

    $log = AuditLog::query()->where('auditable_type', 'donor_activity')->where('auditable_id', $activity->id)
        ->where('event', 'completed')->sole();
    expect($log->user_id)->toBe($actor->id);
});

it('no completa, reprograma ni cancela una actividad que ya no está programada', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $activity = createTestActivity(actor: $actor);
    app(CancelActivity::class)->handle($activity, $actor);

    expect(fn () => app(CompleteActivity::class)->handle($activity, [], $actor))->toThrow(ValidationException::class)
        ->and(fn () => app(RescheduleActivity::class)->handle($activity, now()->addWeek()->toDateTimeString(), $actor))->toThrow(ValidationException::class)
        ->and(fn () => app(CancelActivity::class)->handle($activity, $actor))->toThrow(ValidationException::class);
});

it('reprograma una actividad programada sin cambiar su responsable ni su estado', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $activity = createTestActivity(actor: $actor);
    $newDate = now()->addWeeks(2)->toDateTimeString();

    $rescheduled = app(RescheduleActivity::class)->handle($activity, $newDate, $actor);

    expect($rescheduled->status)->toBe(ActivityStatus::Scheduled)
        ->and($rescheduled->scheduled_at?->toDateTimeString())->toBe($newDate)
        ->and($rescheduled->assigned_to_id)->toBe($activity->assigned_to_id);
});

it('cancela una actividad programada; el historial no se borra', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $activity = createTestActivity(actor: $actor);

    $cancelled = app(CancelActivity::class)->handle($activity, $actor);

    expect($cancelled->status)->toBe(ActivityStatus::Cancelled)
        ->and($cancelled->cancelled_by_id)->toBe($actor->id)
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and(DonorActivity::query()->count())->toBe(1);
});

it('solo Administrador y Coordinador registran y gestionan actividades', function (Role $role, bool $allowed): void {
    $user = userWithRole($role);

    $attempt = fn () => createTestActivity(actor: $user);

    if ($allowed) {
        expect($attempt()->status)->toBe(ActivityStatus::Scheduled);
    } else {
        expect($attempt)->toThrow(AuthorizationException::class);
    }
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, true],
    'Contador' => [Role::Accountant, false],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('revalida el permiso dentro de las Actions aunque no se pase por Filament', function (): void {
    $readOnly = userWithRole(Role::ReadOnly);
    $activity = createTestActivity();

    expect(fn () => app(CompleteActivity::class)->handle($activity, [], $readOnly))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RescheduleActivity::class)->handle($activity, now()->addDay()->toDateTimeString(), $readOnly))->toThrow(AuthorizationException::class)
        ->and(fn () => app(CancelActivity::class)->handle($activity, $readOnly))->toThrow(AuthorizationException::class);
});

it('los cuatro roles pueden ver actividades, pero solo procuración puede gestionarlas (Policy)', function (): void {
    $activity = createTestActivity();

    foreach ([Role::Administrator, Role::FundraisingCoordinator, Role::Accountant, Role::ReadOnly] as $role) {
        $user = userWithRole($role);
        expect(Gate::forUser($user)->allows('view', $activity))->toBeTrue();
    }

    expect(Gate::forUser(userWithRole(Role::FundraisingCoordinator))->allows('complete', $activity))->toBeTrue()
        ->and(Gate::forUser(userWithRole(Role::Accountant))->allows('complete', $activity))->toBeFalse()
        ->and(Gate::forUser(userWithRole(Role::ReadOnly))->allows('complete', $activity))->toBeFalse();
});
