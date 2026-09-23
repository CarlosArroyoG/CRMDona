<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cfdis\Pages;

use App\Filament\Resources\Cfdis\CfdiResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCfdi extends ViewRecord
{
    protected static string $resource = CfdiResource::class;

    protected function getHeaderActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->after(fn () => $this->getRecord()->refresh()),
            CfdiResource::recordActions(),
        );
    }
}
