<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Estrategia central de sanitización (fase-2-diseno-pagos.md §18).
 *
 * - allow(): lista permitida. Copia solo las rutas indicadas y solo valores
 *   escalares; lo demás se descarta. Es la regla para webhook_events.payload.
 * - redact(): lista negada para logs y errores. Oculta llaves sensibles
 *   (secretos, tokens, firmas, tarjeta) y números de tarjeta dentro de textos.
 */
final class SensitiveData
{
    public const string REDACTED = '[redactado]';

    private const int MAX_STRING_LENGTH = 500;

    /**
     * Llaves que nunca se registran, en cualquier nivel.
     */
    private const string SENSITIVE_KEY = '/(secret|token|signature|authorization|password|passwd|cookie|api[_-]?key|card[_-]?number|security[_-]?code|exp_(month|year)|fingerprint|(^|[_-])(cvc|cvv|pan)($|[_-]))/i';

    /**
     * Secuencias de 13 a 19 dígitos (con espacios o guiones opcionales):
     * posibles números de tarjeta.
     */
    private const string CARD_NUMBER = '/\b(?:\d[ -]?){12,18}\d\b/';

    /**
     * Copia solo las rutas permitidas (notación con puntos; `*` recorre una
     * lista; el sufijo `#keys` guarda solo los nombres de las llaves).
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    public static function allow(array $data, array $paths): array
    {
        $result = [];
        foreach ($paths as $path) {
            self::copy($data, explode('.', $path), $result);
        }

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($value) => self::redact($value),
                is_string($value) => self::maskCardNumbers($value),
                default => $value,
            };
        }

        return $clean;
    }

    public static function maskCardNumbers(string $text): string
    {
        return (string) preg_replace(self::CARD_NUMBER, '[número de tarjeta oculto]', $text);
    }

    /**
     * Texto seguro y acotado para columnas de diagnóstico (last_error, provider_message).
     */
    public static function safeText(?string $text, int $limit = self::MAX_STRING_LENGTH): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_substr(self::maskCardNumbers(trim($text)), 0, $limit);
    }

    /**
     * @param  list<string>  $segments
     * @param  array<array-key, mixed>  $target
     */
    private static function copy(mixed $source, array $segments, array &$target): void
    {
        if (! is_array($source) || $segments === []) {
            return;
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            foreach ($source as $key => $value) {
                self::copyValue($value, $segments, $target, $key);
            }

            return;
        }

        if (str_ends_with($segment, '#keys')) {
            $name = substr($segment, 0, -5);
            if (isset($source[$name]) && is_array($source[$name])) {
                $target[$name] = array_values(array_filter(array_keys($source[$name]), 'is_string'));
            }

            return;
        }

        if (array_key_exists($segment, $source)) {
            self::copyValue($source[$segment], $segments, $target, $segment);
        }
    }

    /**
     * @param  list<string>  $remaining
     * @param  array<array-key, mixed>  $target
     */
    private static function copyValue(mixed $value, array $remaining, array &$target, int|string $key): void
    {
        if ($remaining === []) {
            // Solo valores simples: nunca se copia un subárbol completo.
            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                $target[$key] = $value;
            } elseif (is_string($value)) {
                $target[$key] = self::safeText($value);
            }

            return;
        }

        $child = isset($target[$key]) && is_array($target[$key]) ? $target[$key] : [];
        self::copy($value, $remaining, $child);
        if ($child !== []) {
            $target[$key] = $child;
        }
    }
}
