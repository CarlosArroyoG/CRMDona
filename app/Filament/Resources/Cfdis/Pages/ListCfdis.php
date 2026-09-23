<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cfdis\Pages;

use App\Filament\Resources\Cfdis\CfdiResource;
use Filament\Resources\Pages\ListRecords;

class ListCfdis extends ListRecords
{
    protected static string $resource = CfdiResource::class;
}
