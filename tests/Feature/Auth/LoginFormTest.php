<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('muestra en español los errores del formulario vacío', function (): void {
    Livewire::test(Login::class)
        ->set('data.email', '')
        ->set('data.password', '')
        ->call('authenticate')
        ->assertHasErrors(['data.email', 'data.password'])
        ->assertSee('es obligatorio')
        ->assertDontSee('validation.');
});

it('muestra en español el error de credenciales incorrectas', function (): void {
    User::factory()->withRole(Role::Administrator)->create(['email' => 'ana.admin@example.com']);

    Livewire::test(Login::class)
        ->set('data.email', 'ana.admin@example.com')
        ->set('data.password', 'no-es-la-clave-2026')
        ->call('authenticate')
        ->assertHasErrors(['data.email'])
        ->assertSee('Estas credenciales no coinciden con nuestros registros.')
        ->assertDontSee('auth.failed')
        ->assertDontSee('filament-panels::');
});

it('permite iniciar sesión al administrador desde el formulario', function (): void {
    $admin = User::factory()->withRole(Role::Administrator)->create([
        'email' => 'ana.admin@example.com',
        'password' => 'clave-segura-2026',
    ]);

    Livewire::test(Login::class)
        ->set('data.email', 'ana.admin@example.com')
        ->set('data.password', 'clave-segura-2026')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/admin');

    expect(auth()->id())->toBe($admin->getKey());
});
