<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;

it('muestra los mensajes de validación en español', function (): void {
    $messages = Validator::make(['email' => ''], ['email' => 'required'])->errors()->all();

    expect($messages)->toBe(['El campo correo electrónico es obligatorio.']);
});

it('muestra los mensajes de autenticación en español', function (): void {
    expect(__('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('traduce todas las claves que Laravel define en inglés', function (string $file): void {
    $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php");
    $spanish = require lang_path("es/{$file}.php");

    $missing = array_diff(
        array_keys(Arr::dot($english)),
        array_keys(Arr::dot($spanish)),
        // Marcadores de ejemplo de Laravel, no mensajes.
        ['custom.attribute-name.rule-name', 'attributes'],
    );

    expect($missing)->toBe([]);
})->with(['auth', 'pagination', 'passwords', 'validation']);

it('usa formato regional es_MX y moneda MXN', function (): void {
    expect(Number::defaultLocale())->toBe('es_MX')
        ->and(Number::defaultCurrency())->toBe('MXN')
        ->and(ini_get('intl.default_locale'))->toBe('es_MX');
});
