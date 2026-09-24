<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Convierte el teléfono capturado de un donante al formato que pide
 * https://wa.me/<número> (solo dígitos, con lada de país). Es conservador:
 * si el dato es ambiguo devuelve null y el CRM no ofrece WhatsApp, en vez de
 * adivinar un número.
 *
 * - "+52 777 123 4567", "0052…" o "52…" con 10 dígitos nacionales → 52 + 10 dígitos.
 * - "+521…" / "521…" (formato móvil anterior de México) → 52 + 10 dígitos.
 * - 10 dígitos sin lada → número de México (52 + 10 dígitos).
 * - Otro país solo con "+" o "00" explícito y entre 8 y 15 dígitos (E.164).
 * - Letras, extensiones o cualquier otra cantidad de dígitos → null.
 */
final class WhatsAppPhone
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $phone = trim($phone);
        // Solo dígitos, espacios, guiones, puntos, paréntesis y un "+" inicial.
        if (preg_match('/^\+?[0-9\s().\-]+$/', $phone) !== 1) {
            return null;
        }

        $international = str_starts_with($phone, '+');
        $digits = (string) preg_replace('/\D/', '', $phone);
        if (! $international && str_starts_with($digits, '00')) {
            $international = true;
            $digits = substr($digits, 2);
        }

        return match (true) {
            strlen($digits) === 10 && ! $international => '52'.$digits,
            strlen($digits) === 12 && str_starts_with($digits, '52') => $digits,
            strlen($digits) === 13 && str_starts_with($digits, '521') => '52'.substr($digits, 3),
            $international && ! str_starts_with($digits, '52') && strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0' => $digits,
            default => null,
        };
    }

    public static function url(string $normalized, string $text): string
    {
        return 'https://wa.me/'.$normalized.'?text='.rawurlencode($text);
    }
}
