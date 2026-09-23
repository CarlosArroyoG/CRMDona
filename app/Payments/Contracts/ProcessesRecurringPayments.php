<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Enums\RetryOwner;
use App\Enums\SubscriptionInterval;
use App\Payments\Data\CheckoutResult;
use App\Payments\Data\SubscriptionRequest;
use App\Payments\Data\SubscriptionSnapshot;
use App\Payments\Exceptions\PaymentProviderException;

interface ProcessesRecurringPayments
{
    /**
     * @return list<SubscriptionInterval>
     */
    public function supportedIntervals(): array;

    /**
     * Quién reintenta los cobros fallidos con este producto. Si es el
     * proveedor, el CRM solo sincroniza (nunca reintenta en paralelo).
     */
    public function retryOwner(): RetryOwner;

    /**
     * @throws PaymentProviderException
     */
    public function startSubscription(SubscriptionRequest $request): CheckoutResult;

    /**
     * @throws PaymentProviderException
     */
    public function cancelSubscription(string $externalId): SubscriptionSnapshot;
}
