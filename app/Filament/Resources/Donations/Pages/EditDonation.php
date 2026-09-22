<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Actions\Donations\UpdatePendingDonation;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\Donation;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Solo accesible mientras el donativo está "Por confirmar" (DonationPolicy).
 */
class EditDonation extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = DonationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Donation $record */
        return self::withFormErrors(fn () => app(UpdatePendingDonation::class)->handle($record, $data));
    }

    protected function getRedirectUrl(): string
    {
        return DonationResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
