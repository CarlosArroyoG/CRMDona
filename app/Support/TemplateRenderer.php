<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Sustituye variables {{ nombre }} en texto simple. No evalúa código: una
 * plantilla editable nunca ejecuta Blade ni PHP. El resultado es texto plano;
 * quien lo muestre en HTML debe escaparlo.
 *
 * Lanza InvalidArgumentException si la plantilla está rota (llaves sin
 * cerrar) o usa una variable que no está en la lista: quien envía decide usar
 * el texto predeterminado.
 */
final class TemplateRenderer
{
    private const string PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    /**
     * @param  array<string, string>  $variables
     *
     * @throws InvalidArgumentException
     */
    public static function render(string $template, array $variables): string
    {
        self::validate($template, array_keys($variables));

        return (string) preg_replace_callback(self::PATTERN, fn (array $match): string => $variables[$match[1]], $template);
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $template, array $allowed): void
    {
        $stripped = (string) preg_replace(self::PATTERN, '', $template);
        if (str_contains($stripped, '{{') || str_contains($stripped, '}}')) {
            throw new InvalidArgumentException('La plantilla tiene llaves {{ }} sin cerrar o con un nombre de variable inválido.');
        }

        preg_match_all(self::PATTERN, $template, $matches);
        $unknown = array_values(array_diff(array_unique($matches[1]), $allowed));
        if ($unknown !== []) {
            throw new InvalidArgumentException('Variables no disponibles: '.implode(', ', $unknown).'.');
        }
    }
}
