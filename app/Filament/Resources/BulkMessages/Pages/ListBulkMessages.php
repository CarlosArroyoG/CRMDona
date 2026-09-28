<?php

declare(strict_types=1);

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Filament\Resources\BulkMessages\BulkMessageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBulkMessages extends ListRecords
{
    protected static string $resource = BulkMessageResource::class;

    protected ?string $subheading = 'Correos informativos a varios donantes. Solo los reciben quienes aceptan comunicaciones; cada correo lleva enlace de baja.';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo envío masivo'),
        ];
    }
}
