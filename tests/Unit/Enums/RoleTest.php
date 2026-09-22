<?php

declare(strict_types=1);

use App\Enums\Role;

it('guarda valores en inglés y muestra etiquetas en español', function (): void {
    expect(array_map(fn (Role $role): string => $role->value, Role::cases()))
        ->toBe(['administrator', 'fundraising_coordinator', 'accountant', 'read_only'])
        ->and(array_map(fn (Role $role): string => $role->getLabel(), Role::cases()))
        ->toBe(['Administrador', 'Coordinador de procuración de fondos', 'Contador', 'Solo lectura']);
});

it('por ahora solo concede el panel al administrador', function (): void {
    expect(Role::Administrator->canAccessPanel())->toBeTrue()
        ->and(Role::FundraisingCoordinator->canAccessPanel())->toBeFalse()
        ->and(Role::Accountant->canAccessPanel())->toBeFalse()
        ->and(Role::ReadOnly->canAccessPanel())->toBeFalse();
});
