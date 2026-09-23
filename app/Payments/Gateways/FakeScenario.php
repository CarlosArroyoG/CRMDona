<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Enums\FailureCategory;

/**
 * Resultado que simulará la siguiente operación de la FakeGateway.
 */
enum FakeScenario: string
{
    case Success = 'success';
    case Pending = 'pending';
    case Processing = 'processing';
    case Declined = 'declined';
    case ExpiredCard = 'expired_card';
    case InsufficientFunds = 'insufficient_funds';
    // Rechazo definitivo con algo que revisar (no lo corrige el donante).
    case Failed = 'failed';
    case ProviderUnavailable = 'provider_unavailable';
    // El proveedor procesa, pero la respuesta se pierde (timeout).
    case TimeoutAfterProcessing = 'timeout_after_processing';
    case Rejected = 'rejected';

    public function failureCategory(): ?FailureCategory
    {
        return match ($this) {
            self::Declined => FailureCategory::Declined,
            self::ExpiredCard => FailureCategory::ExpiredCard,
            self::InsufficientFunds => FailureCategory::InsufficientFunds,
            self::Failed => FailureCategory::ProcessingError,
            default => null,
        };
    }

    public function providerCode(): ?string
    {
        return match ($this) {
            self::Declined => 'card_declined',
            self::ExpiredCard => 'expired_card',
            self::InsufficientFunds => 'insufficient_funds',
            self::Failed => 'processing_error',
            default => null,
        };
    }
}
