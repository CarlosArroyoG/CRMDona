<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * Lo que el proveedor dice ahora sobre un recurso consultado. Un solo
 * recurso puede afectar varias entidades (por ejemplo, un pago con sus
 * reembolsos).
 */
final readonly class ProviderSnapshot
{
    /**
     * @param  list<PaymentSnapshot>  $payments
     * @param  list<SubscriptionSnapshot>  $subscriptions
     * @param  list<RefundSnapshot>  $refunds
     * @param  list<DisputeSnapshot>  $disputes
     */
    public function __construct(
        public array $payments = [],
        public array $subscriptions = [],
        public array $refunds = [],
        public array $disputes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->payments === [] && $this->subscriptions === [] && $this->refunds === [] && $this->disputes === [];
    }
}
