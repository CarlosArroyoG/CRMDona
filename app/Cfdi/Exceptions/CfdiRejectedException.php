<?php

declare(strict_types=1);

namespace App\Cfdi\Exceptions;

use RuntimeException;

/**
 * El PAC o el SAT rechazaron el CFDI por sus datos (RFC, régimen, código
 * postal…). Hay que corregir y reintentar. Mensaje propio y seguro.
 */
final class CfdiRejectedException extends RuntimeException
{
    public function __construct(string $safeMessage, public readonly ?string $errorCode = null)
    {
        parent::__construct($safeMessage);
    }
}
