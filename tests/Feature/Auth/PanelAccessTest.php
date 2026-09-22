<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('permite entrar al panel a los cuatro roles', function (Role $role): void {
    actingAs(userWithRole($role))->get('/admin')->assertOk();
})->with(Role::cases());

it('niega el panel a un usuario sin rol', function (): void {
    actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});

it('niega el panel a un usuario desactivado aunque tenga rol', function (): void {
    $user = User::factory()->withRole(Role::Administrator)->create(['deactivated_at' => now()]);

    actingAs($user)->get('/admin')->assertForbidden();
});

it('redirige al visitante al inicio de sesión', function (): void {
    get('/admin')->assertRedirect('/admin/login');
});

it('niega el panel a un usuario sin rol también en producción', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    actingAs(User::factory()->create())->get('/admin')->assertForbidden();
});
