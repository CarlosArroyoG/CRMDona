<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Conserva solo la clase de la excepción original (útil para soporte) y
 * descarta su mensaje, que puede traer datos del proveedor.
 */
final class ProviderErrorSummary extends RuntimeException
{
    public function __construct(Throwable $original)
    {
        parent::__construct('Excepción original: '.$original::class);
    }
}
