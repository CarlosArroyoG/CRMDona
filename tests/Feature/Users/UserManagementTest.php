<?php

declare(strict_types=1);

use App\Actions\Users\CreateUser;
use App\Actions\Users\SetUserActive;
use App\Actions\Users\UpdateUser;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

/**
 * @return array<string, array<int, string>>
 */
function userErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Se esperaba un error de validación.');
}

it('crea usuarios con cualquiera de los cuatro roles y contraseña cifrada', function (Role $role): void {
    $user = app(CreateUser::class)->handle('Persona Ficticia', 'persona@example.com', $role, 'clave-segura-2026', 'clave-segura-2026');

    expect($user->role)->toBe($role)
        ->and($user->isActive())->toBeTrue()
        ->and(Hash::check('clave-segura-2026', (string) $user->getAuthPassword()))->toBeTrue();
})->with(Role::cases());

it('aplica la política de contraseña de la Fase 0', function (): void {
    expect(userErrors(fn () => app(CreateUser::class)->handle('X', 'x@example.com', Role::ReadOnly, 'corta1', 'corta1')))
        ->toHaveKey('password')
        ->and(userErrors(fn () => app(CreateUser::class)->handle('X', 'x@example.com', null, 'clave-segura-2026', 'clave-segura-2026')))
        ->toHaveKey('role');
});

it('cambia el rol y lo registra con valor en la bitácora', function (): void {
    userWithRole(Role::Administrator);
    $user = userWithRole(Role::ReadOnly);

    app(UpdateUser::class)->handle($user, $user->name, $user->email, Role::Accountant);

    $log = AuditLog::query()->where('auditable_type', 'user')->where('auditable_id', $user->id)->latest('id')->firstOrFail();
    expect($user->fresh()?->role)->toBe(Role::Accountant)
        ->and($log->old_values)->toBe(['role' => 'read_only'])
        ->and($log->new_values)->toBe(['role' => 'accountant']);
});

it('impide quedarse sin Administradores activos', function (): void {
    $onlyAdmin = userWithRole(Role::Administrator);

    expect(userErrors(fn () => app(UpdateUser::class)->handle($onlyAdmin, $onlyAdmin->name, $onlyAdmin->email, Role::ReadOnly)))
        ->toHaveKey('role');

    $otherAdmin = userWithRole(Role::Administrator);
    expect(userErrors(fn () => app(SetUserActive::class)->handle($otherAdmin, false, $otherAdmin)))->toHaveKey('user');

    app(SetUserActive::class)->handle($otherAdmin, false, $onlyAdmin);
    expect(userErrors(fn () => app(SetUserActive::class)->handle($onlyAdmin, false, userWithRole(Role::Accountant))))->toHaveKey('user');
});

it('desactiva y reactiva usuarios; el desactivado no entra ni tiene permisos', function (): void {
    $admin = userWithRole(Role::Administrator);
    $user = userWithRole(Role::FundraisingCoordinator);

    app(SetUserActive::class)->handle($user, false, $admin);
    $user->refresh();

    expect($user->isActive())->toBeFalse()
        ->and($user->hasPermission(Permission::ViewDonors))->toBeFalse()
        ->and(AuditLog::query()->where('event', AuditEvent::Deactivated->value)->exists())->toBeTrue();
    actingAs($user)->get('/admin')->assertForbidden();

    app(SetUserActive::class)->handle($user, true, $admin);
    actingAs($user->refresh())->get('/admin')->assertOk();
});
