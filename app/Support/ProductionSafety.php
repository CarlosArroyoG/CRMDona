<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Candados de configuración en producción: no dependen de que alguien capture
 * bien las variables en Coolify.
 *
 * - Con APP_DEBUG=true la aplicación no arranca: la página de error mostraría
 *   código, consultas y rutas internas a cualquiera.
 * - La cookie de sesión viaja solo por HTTPS, aunque SESSION_SECURE_COOKIE
 *   falte o diga false.
 */
final class ProductionSafety
{
    public const string DEBUG_ENABLED = 'APP_DEBUG=true no se permite en producción: cámbialo a false en las variables del recurso y vuelve a desplegar.';

    /**
     * @return list<string>
     */
    public static function problems(bool $production, bool $debug): array
    {
        return $production && $debug ? [self::DEBUG_ENABLED] : [];
    }

    /**
     * @throws RuntimeException
     */
    public static function enforce(bool $production): void
    {
        if (! $production) {
            return;
        }

        $problems = self::problems($production, config()->boolean('app.debug'));
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }

        config(['session.secure' => true]);
    }
}
