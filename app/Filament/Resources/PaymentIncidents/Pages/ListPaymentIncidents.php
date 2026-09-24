<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentIncidents\Pages;

use App\Filament\Resources\PaymentIncidents\PaymentIncidentResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentIncidents extends ListRecords
{
    protected static string $resource = PaymentIncidentResource::class;

    protected ?string $subheading = 'Situaciones de pagos en línea que requieren revisión; cada una indica qué hacer.';
}
