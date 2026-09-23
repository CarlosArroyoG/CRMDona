<?php

declare(strict_types=1);

namespace App\Payments\Exceptions;

use RuntimeException;

/**
 * El proveedor reporta algo (por ejemplo, una disputa) sobre un pago que el
 * CRM todavía no conoce. El Job reintenta: la notificación del pago puede
 * llegar después.
 */
final class PaymentReferenceNotFoundException extends RuntimeException {}
