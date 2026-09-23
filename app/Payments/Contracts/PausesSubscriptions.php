<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Payments\Data\SubscriptionSnapshot;
use App\Payments\Exceptions\PaymentProviderException;

/**
 * Capacidad opcional: pausar y reactivar un donativo mensual.
 */
interface PausesSubscriptions
{
    /**
     * @throws PaymentProviderException
     */
    public function pauseSubscription(string $externalId): SubscriptionSnapshot;

    /**
     * @throws PaymentProviderException
     */
    public function resumeSubscription(string $externalId): SubscriptionSnapshot;
}
