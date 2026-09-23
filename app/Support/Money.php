<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Importes en MXN como strings decimales exactos (ADR-004). Nunca float: el
 * redondeo binario (0.1 + 0.2) no debe llegar a un donativo. Usa bcmath.
 */
final class Money
{
    public const MAX = '9999999999.99';

    private const PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    /**
     * Acepta "1234", "1,234.5" o 1234 y devuelve "1234.50". Rechaza float,
     * negativos, cero y más de dos decimales.
     *
     * @return numeric-string
     */
    public static function normalize(string|int $value): string
    {
        $clean = self::clean((string) $value);

        if (! is_numeric($clean) || ! self::isValid($clean)) {
            throw new InvalidArgumentException('El importe debe ser un número positivo con máximo dos decimales.');
        }

        return bcadd($clean, '0', 2);
    }

    public static function isValid(string $value): bool
    {
        $clean = self::clean($value);

        return preg_match(self::PATTERN, $clean) === 1
            && is_numeric($clean)
            && bccomp($clean, '0', 2) === 1
            && bccomp($clean, self::MAX, 2) <= 0;
    }

    /**
     * "1234.5" → "$1,234.50".
     */
    public static function format(?string $amount): string
    {
        if ($amount === null || ! is_numeric($amount)) {
            return '—';
        }

        [$integer, $decimals] = explode('.', bcadd($amount, '0', 2));
        $negative = str_starts_with($integer, '-');
        $grouped = strrev(implode(',', str_split(strrev(ltrim($integer, '-')), 3)));

        return ($negative ? '-$' : '$').$grouped.'.'.$decimals;
    }

    /**
     * @return numeric-string
     */
    public static function add(string $a, string $b): string
    {
        return bcadd(self::numeric($a), self::numeric($b), 2);
    }

    /**
     * @return numeric-string
     */
    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::numeric($a), self::numeric($b), 2);
    }

    /**
     * -1, 0 o 1, como bccomp, con dos decimales.
     */
    public static function compare(string $a, string $b): int
    {
        return bccomp(self::numeric($a), self::numeric($b), 2);
    }

    /**
     * @return numeric-string
     */
    private static function numeric(string $value): string
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Importe inválido.');
        }

        return $value;
    }

    private static function clean(string $value): string
    {
        return str_replace([',', ' ', '$'], '', trim($value));
    }
}
