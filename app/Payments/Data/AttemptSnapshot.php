<?php

declare(strict_types=1);

namespace App\Payments\Data;

use App\Enums\AttemptInitiator;
use App\Enums\CardFunding;
use App\Enums\FailureCategory;
use App\Enums\PaymentAttemptStatus;
use Carbon\CarbonImmutable;

/**
 * Un intento de cobro tal como lo reporta el proveedor, ya normalizado.
 * `providerMessage` debe llegar sanitizado (sin datos de tarjeta).
 */
final readonly class AttemptSnapshot
{
    public function __construct(
        public string $externalId,
        public PaymentAttemptStatus $status,
        public AttemptInitiator $initiatedBy,
        public ?FailureCategory $failureCategory = null,
        public ?string $providerCode = null,
        public ?string $providerMessage = null,
        public ?string $cardBrand = null,
        public ?string $cardLast4 = null,
        public ?CardFunding $cardFunding = null,
        public ?CarbonImmutable $providerCreatedAt = null,
    ) {}
}
