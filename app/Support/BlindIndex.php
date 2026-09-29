<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Huella (HMAC-SHA256) de un dato cifrado, para buscarlo por igualdad sin
 * guardarlo legible: el RFC se cifra en la base y solo su huella permite
 * encontrar duplicados o buscar un RFC completo.
 *
 * La llave se deriva de APP_KEY, así que una copia robada de la base no
 * permite recalcular huellas ni descifrar. Si se rota APP_KEY hay que volver
 * a cifrar y recalcular las huellas (docs/tecnico/proteccion-de-datos.md).
 */
final class BlindIndex
{
    public static function rfc(string $rfc): string
    {
        return hash_hmac('sha256', 'rfc|'.mb_strtoupper(trim($rfc)), self::key());
    }

    private static function key(): string
    {
        $appKey = config()->string('app.key');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY no está configurada: no se pueden calcular huellas de datos cifrados.');
        }

        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return hash_hmac('sha256', 'crm-blind-index-v1', $raw, true);
    }
}
