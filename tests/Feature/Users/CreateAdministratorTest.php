<?php

declare(strict_types=1);

use App\Actions\Users\CreateAdministrator;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

const VALID_PASSWORD = 'clave-segura-2026';

function createAdministrator(string $email = 'ana.admin@example.com', string $password = VALID_PASSWORD, ?string $confirmation = null): User
{
    return app(CreateAdministrator::class)->handle('Ana Admin', $email, $password, $confirmation ?? $password);
}

/**
 * @return array<string, array<int, string>>
 */
function validationErrorsOf(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Se esperaba un error de validación.');
}

it('crea un administrador con el rol Administrator', function (): void {
    $user = createAdministrator();

    expect($user->fresh()?->role)->toBe(Role::Administrator)
        ->and($user->email)->toBe('ana.admin@example.com')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('guarda la contraseña solo como hash', function (): void {
    $user = createAdministrator();
    $stored = (string) User::query()->whereKey($user->getKey())->value('password');

    expect($stored)->not->toBe(VALID_PASSWORD)
        ->and(Hash::check(VALID_PASSWORD, $stored))->toBeTrue();
});

it('normaliza el correo a minúsculas', function (): void {
    expect(createAdministrator(email: '  Ana.Admin@Example.COM ')->email)->toBe('ana.admin@example.com');
});

it('rechaza un correo inválido', function (): void {
    $errors = validationErrorsOf(fn () => createAdministrator(email: 'no-es-un-correo'));

    expect($errors['email'])->toBe(['El campo correo electrónico debe ser un correo electrónico válido.'])
        ->and(User::query()->count())->toBe(0);
});

it('rechaza un correo que ya existe sin modificar al usuario', function (): void {
    $existing = User::factory()->withRole(Role::ReadOnly)->create(['email' => 'ana.admin@example.com']);

    $errors = validationErrorsOf(fn () => createAdministrator(email: 'ANA.ADMIN@example.com'));

    expect($errors['email'][0])->toContain('Ese correo ya pertenece a un usuario')
        ->and($existing->fresh()?->role)->toBe(Role::ReadOnly)
        ->and(User::query()->count())->toBe(1);
});

it('rechaza contraseñas que no cumplen la política', function (string $password, string $message): void {
    $errors = validationErrorsOf(fn () => createAdministrator(password: $password));

    expect($errors['password'])->toContain($message)
        ->and(User::query()->count())->toBe(0);
})->with([
    'muy corta' => ['abc123', 'El campo contraseña debe tener al menos 12 caracteres.'],
    'sin números' => ['solo-letras-aqui', 'El campo contraseña debe contener al menos un número.'],
    'sin letras' => ['123456789012', 'El campo contraseña debe contener al menos una letra.'],
]);

it('rechaza una confirmación distinta', function (): void {
    $errors = validationErrorsOf(fn () => createAdministrator(confirmation: 'otra-clave-2026'));

    expect($errors['password'])->toContain('La confirmación de contraseña no coincide.')
        ->and(User::query()->count())->toBe(0);
});
