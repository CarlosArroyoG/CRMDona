<?php

declare(strict_types=1);

use App\Enums\Role;

use function Pest\Laravel\actingAs;

/*
 * Navegación organizada por tareas: el diagnóstico técnico queda en un grupo
 * aparte solo para el Administrador, y el Escritorio ofrece a cada rol solo
 * los accesos que su permiso ya le da.
 */
it('agrupa la navegación por tareas y deja el diagnóstico técnico solo al Administrador', function (Role $role, bool $seesSupport): void {
    $response = actingAs(userWithRole($role))->get('/admin')->assertOk()
        ->assertSee('Escritorio')
        ->assertSee('Donativos')
        ->assertSee('Recaudación')
        ->assertSee('Donativos mensuales')
        ->assertDontSee('Bandeja de webhooks');

    $seesSupport
        ? $response->assertSee('Soporte técnico')->assertSee('Notificaciones de proveedores')
        : $response->assertDontSee('Soporte técnico')->assertDontSee('Notificaciones de proveedores');
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, false],
    'Contador' => [Role::Accountant, false],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('muestra en el Escritorio solo los accesos y pendientes que el rol puede abrir', function (Role $role, bool $registers, bool $accounting): void {
    $response = actingAs(userWithRole($role))->get('/admin')->assertOk()
        ->assertSee('Ver donativos')
        ->assertSee('Donativos por confirmar');

    $registers ? $response->assertSee('Registrar donativo') : $response->assertDontSee('Registrar donativo');
    $accounting ? $response->assertSee('Pendientes de Contabilidad') : $response->assertDontSee('Pendientes de Contabilidad');
})->with([
    'Administrador' => [Role::Administrator, true, true],
    'Coordinador' => [Role::FundraisingCoordinator, true, true],
    'Contador' => [Role::Accountant, true, true],
    'Solo lectura' => [Role::ReadOnly, false, false],
]);
