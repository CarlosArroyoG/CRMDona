<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donations\Pages;

use App\Actions\Donations\RegisterDonation;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Donations\DonationResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDonation extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = DonationResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(RegisterDonation::class)->handle($data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return DonationResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Donativo registrado "Por confirmar"';
    }
}
