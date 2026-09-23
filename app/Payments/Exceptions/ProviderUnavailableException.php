<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

/**
 * El proveedor no respondió (timeout, error 5xx, red). La operación pudo o no
 * haberse ejecutado del lado del proveedor: se reintenta con la misma llave
 * de idempotencia.
 */
final class ProviderUnavailableException extends PaymentProviderException {}
