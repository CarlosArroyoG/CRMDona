<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentIncidents\Pages;

use App\Filament\Resources\PaymentIncidents\PaymentIncidentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPaymentIncident extends ViewRecord
{
    protected static string $resource = PaymentIncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PaymentIncidentResource::takeAction()->after(fn () => $this->getRecord()->refresh()),
            PaymentIncidentResource::addNoteAction()->after(fn () => $this->getRecord()->refresh()),
            PaymentIncidentResource::resolveAction()->after(fn () => $this->getRecord()->refresh()),
        ];
    }
}
