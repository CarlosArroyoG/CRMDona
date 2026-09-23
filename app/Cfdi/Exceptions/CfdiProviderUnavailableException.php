<?php

declare(strict_types=1);

namespace App\Cfdi\Exceptions;

use RuntimeException;

/**
 * El PAC no respondió (timeout, 5xx, red). Se reintenta con la misma llave;
 * el mensaje es propio y seguro, nunca el cuerpo de la respuesta.
 */
final class CfdiProviderUnavailableException extends RuntimeException {}
