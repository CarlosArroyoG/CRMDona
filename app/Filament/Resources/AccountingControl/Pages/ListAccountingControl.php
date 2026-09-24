<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountingControl\Pages;

use App\Filament\Resources\AccountingControl\AccountingControlResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountingControl extends ListRecords
{
    protected static string $resource = AccountingControlResource::class;

    protected ?string $subheading = 'Seguimiento contable de cada donativo confirmado: recibo, CFDI solicitado y procesamiento.';
}
