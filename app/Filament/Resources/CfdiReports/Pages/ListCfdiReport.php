<?php

declare(strict_types=1);

namespace App\Filament\Resources\CfdiReports\Pages;

use App\Filament\Resources\CfdiReports\CfdiReportResource;
use Filament\Resources\Pages\ListRecords;

class ListCfdiReport extends ListRecords
{
    protected static string $resource = CfdiReportResource::class;
}
