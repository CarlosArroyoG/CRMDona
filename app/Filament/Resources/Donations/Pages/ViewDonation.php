<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Filament\Resources\Donations\DonationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDonation extends ViewRecord
{
    protected static string $resource = DonationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DonationResource::confirmAction()->after(fn () => $this->getRecord()->refresh()),
            DonationResource::cancelAction()->after(fn () => $this->getRecord()->refresh()),
        ];
    }
}
