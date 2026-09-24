<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\Pages;

use App\Filament\Actions\PrepareWhatsAppAction;
use App\Filament\Resources\Donors\DonorResource;
use App\Models\Donor;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDonor extends ViewRecord
{
    protected static string $resource = DonorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            // Felicitación de cumpleaños por WhatsApp (solo si hay fecha de nacimiento).
            PrepareWhatsAppAction::make(fn (): ?Donor => $this->getRecord() instanceof Donor && $this->getRecord()->birth_date !== null ? $this->getRecord() : null),
            DonorResource::taxProfileAction(),
            DonorResource::archiveAction(),
            DonorResource::deleteAction(),
        ];
    }
}
