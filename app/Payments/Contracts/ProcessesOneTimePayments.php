<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Payments\Data\CheckoutResult;
use App\Payments\Data\OneTimePaymentRequest;
use App\Payments\Exceptions\PaymentProviderException;

interface ProcessesOneTimePayments
{
    /**
     * Idempotente: la misma llave devuelve el mismo resultado del proveedor.
     *
     * @throws PaymentProviderException
     */
    public function startOneTimePayment(OneTimePaymentRequest $request): CheckoutResult;
}
