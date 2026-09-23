<?php

declare(strict_types=1);

namespace App\Cfdi\Data;

/**
 * Respuesta del PAC a una cancelación [V SAT]: cancelada, en espera de la
 * aceptación del receptor (hasta 3 días hábiles) o rechazada.
 */
final readonly class CancellationResult
{
    public const string CANCELLED = 'cancelled';

    public const string PENDING_ACCEPTANCE = 'pending_acceptance';

    public const string REJECTED = 'rejected';

    public function __construct(
        public string $outcome,
        public string $providerStatus,
    ) {}
}
