<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Frecuencia del donativo recurrente. Solo mensual en esta versión; para
 * agregar otra basta un caso aquí, en el CHECK y en los adaptadores.
 */
enum SubscriptionInterval: string implements HasLabel
{
    case Monthly = 'monthly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Monthly => 'Mensual',
        };
    }
}
