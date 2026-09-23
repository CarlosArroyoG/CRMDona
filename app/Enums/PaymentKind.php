<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentKind: string implements HasLabel
{
    case OneTime = 'one_time';
    case RecurringCharge = 'recurring_charge';

    public function getLabel(): string
    {
        return match ($this) {
            self::OneTime => 'Único',
            self::RecurringCharge => 'Mensualidad',
        };
    }
}
