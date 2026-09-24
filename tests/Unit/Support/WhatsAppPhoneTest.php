<?php

declare(strict_types=1);

use App\Support\WhatsAppPhone;

it('normaliza teléfonos de México y E.164 explícitos', function (?string $phone, ?string $expected): void {
    expect(WhatsAppPhone::normalize($phone))->toBe($expected);
})->with([
    'celular de 10 dígitos con espacios' => ['777 123 4567', '527771234567'],
    'con guiones y paréntesis' => ['(777) 123-4567', '527771234567'],
    '+52 con 10 dígitos' => ['+52 777 123 4567', '527771234567'],
    '0052 internacional' => ['0052 777 123 4567', '527771234567'],
    '52 sin +' => ['527771234567', '527771234567'],
    '+521 formato móvil anterior' => ['+52 1 777 123 4567', '527771234567'],
    'otro país con + explícito' => ['+1 415 555 2671', '14155552671'],
    'España con 00' => ['0034 612 345 678', '34612345678'],
    'vacío' => ['', null],
    'nulo' => [null, null],
    'letras o extensión' => ['777 123 4567 ext 12', null],
    'muy corto' => ['1234567', null],
    '11 dígitos sin lada (ambiguo)' => ['17771234567', null],
    '+52 con 9 dígitos' => ['+52 777 123 456', null],
    '+ con más de 15 dígitos' => ['+1234567890123456', null],
]);

it('arma el enlace oficial wa.me con el texto codificado', function (): void {
    expect(WhatsAppPhone::url('527771234567', '¡Feliz cumpleaños, María! & gracias'))
        ->toBe('https://wa.me/527771234567?text=%C2%A1Feliz%20cumplea%C3%B1os%2C%20Mar%C3%ADa%21%20%26%20gracias');
});
