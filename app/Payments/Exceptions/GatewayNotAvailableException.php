<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * La pasarela no está habilitada o no está configurada en este entorno.
 */
final class GatewayNotAvailableException extends RuntimeException {}
