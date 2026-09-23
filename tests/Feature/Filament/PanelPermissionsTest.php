<?php

declare(strict_types=1);

use App\Enums\Role;

use function Pest\Laravel\actingAs;

/*
 * Qué pantallas abre cada rol (200) y cuáles le están prohibidas (403),
 * según la matriz aprobada.
 */
dataset('screens', [
    // ruta, Administrador, Coordinador, Contador, Solo lectura
    'donantes' => ['/admin/donors', 200, 200, 200, 200],
    'nuevo donante' => ['/admin/donors/create', 200, 200, 403, 403],
    'donativos' => ['/admin/donations', 200, 200, 200, 200],
    'nuevo donativo' => ['/admin/donations/create', 200, 200, 200, 403],
    'programas' => ['/admin/programs', 200, 200, 200, 200],
    'nuevo programa' => ['/admin/programs/create', 200, 200, 403, 403],
    'campañas' => ['/admin/campaigns', 200, 200, 200, 200],
    'nueva campaña' => ['/admin/campaigns/create', 200, 200, 403, 403],
    'usuarios' => ['/admin/users', 200, 403, 403, 403],
    'nuevo usuario' => ['/admin/users/create', 200, 403, 403, 403],
    'bitácora' => ['/admin/audit-logs', 200, 403, 403, 403],
    'organización' => ['/admin/organizacion', 200, 403, 200, 403],
    'cambiar contraseña' => ['/admin/profile', 200, 200, 200, 200],
    // Fase 2
    'pagos en línea' => ['/admin/payments', 200, 200, 200, 200],
    'donativos mensuales' => ['/admin/subscriptions', 200, 200, 200, 200],
    'incidencias' => ['/admin/payment-incidents', 200, 200, 200, 403],
    'reembolsos' => ['/admin/refunds', 200, 403, 200, 403],
    'disputas' => ['/admin/payment-disputes', 200, 403, 200, 403],
    'bandeja de webhooks' => ['/admin/webhook-events', 200, 403, 403, 403],
    'pasarelas de pago' => ['/admin/pasarelas', 200, 403, 403, 403],
    // Fase 3
    'CFDI' => ['/admin/cfdis', 200, 200, 200, 403],
]);

it('abre o prohíbe cada pantalla según el rol', function (string $url, int $admin, int $coordinator, int $accountant, int $readOnly): void {
    $expected = [
        Role::Administrator->value => $admin,
        Role::FundraisingCoordinator->value => $coordinator,
        Role::Accountant->value => $accountant,
        Role::ReadOnly->value => $readOnly,
    ];

    foreach (Role::cases() as $role) {
        actingAs(userWithRole($role))->get($url)->assertStatus($expected[$role->value]);
    }
})->with('screens');
