<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use App\Enums\PaymentProvider;
use RuntimeException;
use Throwable;

/**
 * Error al hablar con un proveedor. El mensaje lo escribe el adaptador y es
 * seguro para logs y pantallas: nunca incluye el cuerpo de la respuesta,
 * encabezados, llaves ni datos de tarjeta. La excepción original no se
 * encadena para que su mensaje (que podría contener datos del proveedor)
 * no llegue a los logs.
 */
class PaymentProviderException extends RuntimeException
{
    public function __construct(
        public readonly PaymentProvider $provider,
        string $safeMessage,
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous !== null ? new ProviderErrorSummary($previous) : null);
    }
}
