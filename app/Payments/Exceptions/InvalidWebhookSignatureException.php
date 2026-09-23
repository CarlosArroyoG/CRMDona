<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * La notificación no está firmada por el proveedor (o la firma no coincide).
 * No se guarda nada.
 */
final class InvalidWebhookSignatureException extends RuntimeException {}
