<?php

declare(strict_types=1);

namespace App\Enums;

use App\PublicDonations\ValidatePublicDonationForm;
use Filament\Support\Contracts\HasLabel;

/**
 * Frecuencia de una solicitud de pago. Los valores son los mismos que usa la
 * página pública, así el enlace reutiliza su flujo sin traducciones.
 */
enum PaymentRequestFrequency: string implements HasLabel
{
    case OneTime = ValidatePublicDonationForm::ONE_TIME;
    case Monthly = ValidatePublicDonationForm::MONTHLY;

    public function getLabel(): string
    {
        return match ($this) {
            self::OneTime => 'Una sola vez',
            self::Monthly => 'Cada mes',
        };
    }
}
