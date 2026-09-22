<?php

declare(strict_types=1);

use App\Support\Money;

it('normaliza importes a dos decimales exactos', function (string|int $input, string $expected): void {
    expect(Money::normalize($input))->toBe($expected);
})->with([
    ['1234', '1234.00'],
    ['1234.5', '1234.50'],
    ['1,234.56', '1234.56'],
    ['$ 1,000', '1000.00'],
    [250, '250.00'],
    ['0.01', '0.01'],
    ['9999999999.99', '9999999999.99'],
]);

it('rechaza importes inválidos', function (string $input): void {
    expect(Money::isValid($input))->toBeFalse()
        ->and(fn () => Money::normalize($input))->toThrow(InvalidArgumentException::class);
})->with(['0', '0.00', '-5', '12.345', 'abc', '', '10000000000.00', '1e3']);

it('suma sin errores de punto flotante (en float, 0.1 + 0.2 da 0.30000000000000004)', function (): void {
    expect(bcadd(Money::normalize('0.10'), Money::normalize('0.20'), 2))->toBe('0.30');
});

it('da formato de moneda para mostrar', function (?string $amount, string $expected): void {
    expect(Money::format($amount))->toBe($expected);
})->with([
    ['1234.5', '$1,234.50'],
    ['1000000', '$1,000,000.00'],
    ['0.5', '$0.50'],
    ['999', '$999.00'],
    [null, '—'],
]);
