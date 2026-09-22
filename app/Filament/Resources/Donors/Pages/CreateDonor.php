<?php

declare(strict_types=1);

namespace App\Filament\Resources\Donors\Pages;

use App\Actions\Donors\SaveDonor;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Donors\DonorResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateDonor extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = DonorResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(SaveDonor::class)->handle(null, $data, $actor));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Donante registrado';
    }
}
