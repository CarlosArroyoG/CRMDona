<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

const COMMAND_PASSWORD = 'clave-de-consola-2026';

function createAdminCommand(): PendingCommand
{
    $command = artisan('app:create-admin');
    assert($command instanceof PendingCommand);

    return $command;
}

it('crea el administrador con los datos capturados', function (): void {
    createAdminCommand()
        ->expectsQuestion('Nombre', 'Beto Admin')
        ->expectsQuestion('Correo electrónico', 'beto.admin@example.com')
        ->expectsQuestion('Contraseña', COMMAND_PASSWORD)
        ->expectsQuestion('Confirma la contraseña', COMMAND_PASSWORD)
        ->expectsOutputToContain('Administrador creado: beto.admin@example.com')
        ->doesntExpectOutputToContain(COMMAND_PASSWORD)
        ->assertSuccessful();

    expect(User::query()->where('email', 'beto.admin@example.com')->first()?->role)
        ->toBe(Role::Administrator);
});

it('falla sin mostrar la contraseña cuando el correo ya existe', function (): void {
    User::factory()->create(['email' => 'beto.admin@example.com']);

    createAdminCommand()
        ->expectsQuestion('Nombre', 'Beto Admin')
        ->expectsQuestion('Correo electrónico', 'beto.admin@example.com')
        ->expectsQuestion('Contraseña', COMMAND_PASSWORD)
        ->expectsQuestion('Confirma la contraseña', COMMAND_PASSWORD)
        ->expectsOutputToContain('No se creó el administrador.')
        ->expectsOutputToContain('Ese correo ya pertenece a un usuario')
        ->doesntExpectOutputToContain(COMMAND_PASSWORD)
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

it('falla sin mostrar la contraseña cuando la confirmación no coincide', function (): void {
    createAdminCommand()
        ->expectsQuestion('Nombre', 'Beto Admin')
        ->expectsQuestion('Correo electrónico', 'beto.admin@example.com')
        ->expectsQuestion('Contraseña', COMMAND_PASSWORD)
        ->expectsQuestion('Confirma la contraseña', 'otra-clave-2026')
        ->expectsOutputToContain('La confirmación de contraseña no coincide.')
        ->doesntExpectOutputToContain(COMMAND_PASSWORD)
        ->doesntExpectOutputToContain('otra-clave-2026')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});
