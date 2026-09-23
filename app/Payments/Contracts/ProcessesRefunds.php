<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Enums\RefundReason;
use App\Payments\Data\RefundRequest;
use App\Payments\Data\RefundSnapshot;
use App\Payments\Exceptions\PaymentProviderException;

/**
 * Capacidad opcional: reembolsos parciales y totales.
 */
interface ProcessesRefunds
{
    /**
     * Motivo que acepta la API del proveedor, o nulo si no hay equivalente.
     * Nunca devuelve texto libre del CRM.
     */
    public function providerRefundReason(RefundReason $reason): ?string;

    /**
     * Idempotente con `RefundRequest::$idempotencyKey`.
     *
     * @throws PaymentProviderException
     */
    public function refund(RefundRequest $request): RefundSnapshot;
}
