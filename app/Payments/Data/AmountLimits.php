<?php

declare(strict_types=1);

namespace App\Payments\Data;

/**
 * Límites técnicos de un proveedor para MXN con tarjeta. Nulo significa que
 * no hay un límite verificado que aplicar (nunca se inventa un valor).
 */
final readonly class AmountLimits
{
    public function __construct(
        public ?string $min = null,
        public ?string $max = null,
        public string $source = '',
    ) {}
}
