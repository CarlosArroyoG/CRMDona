<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentDisputes\Pages;

use App\Filament\Resources\PaymentDisputes\PaymentDisputeResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentDisputes extends ListRecords
{
    protected static string $resource = PaymentDisputeResource::class;

    protected ?string $subheading = 'Aclaraciones y contracargos que el banco del donante abrió sobre un pago en línea.';
}
