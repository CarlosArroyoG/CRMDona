<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use App\Enums\FailureCategory;
use App\Enums\PaymentProvider;
use Throwable;

/**
 * El proveedor respondió y rechazó la operación de forma definitiva (datos
 * inválidos, reembolso no permitido…). Reintentar con la misma llave no
 * cambia el resultado.
 */
final class ProviderRejectedException extends PaymentProviderException
{
    public function __construct(
        PaymentProvider $provider,
        string $safeMessage,
        ?string $providerCode = null,
        public readonly FailureCategory $category = FailureCategory::Unknown,
        ?Throwable $previous = null,
    ) {
        parent::__construct($provider, $safeMessage, $providerCode, $previous);
    }
}
