<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('muestra la página inicial', function (): void {
    get('/')->assertOk();
});

it('muestra el inicio de sesión del panel interno', function (): void {
    get('/admin/login')->assertOk();
});

it('redirige al inicio de sesión a quien entra al panel sin autenticarse', function (): void {
    get('/admin')->assertRedirect('/admin/login');
});

it('usa la configuración regional de México', function (): void {
    expect(config('app.locale'))->toBe('es')
        ->and(config('app.faker_locale'))->toBe('es_MX')
        ->and(config('app.timezone'))->toBe('America/Mexico_City')
        ->and(now()->getTimezone()->getName())->toBe('America/Mexico_City');
});

it('usa PostgreSQL como base de datos', function (): void {
    expect(config('database.default'))->toBe('pgsql');
});
