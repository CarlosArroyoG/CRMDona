<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentRequests\Pages;

use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentRequests extends ListRecords
{
    protected static string $resource = PaymentRequestResource::class;

    protected ?string $subheading = 'Cobros con tarjeta preparados para un donante: enlace, situación y pago. Se crean desde "Crear donativo".';
}
