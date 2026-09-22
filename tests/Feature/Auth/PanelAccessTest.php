<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('permite al administrador entrar al panel', function (): void {
    actingAs(User::factory()->withRole(Role::Administrator)->create())
        ->get('/admin')
        ->assertOk();
});

it('niega el panel a un usuario sin rol', function (): void {
    actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('niega el panel a los roles que aún no tienen módulos', function (Role $role): void {
    actingAs(User::factory()->withRole($role)->create())
        ->get('/admin')
        ->assertForbidden();
})->with([Role::FundraisingCoordinator, Role::Accountant, Role::ReadOnly]);

it('redirige al visitante al inicio de sesión', function (): void {
    get('/admin')->assertRedirect('/admin/login');
});

it('niega el panel a un usuario sin rol también en producción', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    actingAs(User::factory()->create())
        ->get('/admin')
        ->assertForbidden();
});
