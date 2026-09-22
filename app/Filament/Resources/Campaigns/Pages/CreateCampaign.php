<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Pages;

use App\Actions\Campaigns\SaveCampaign;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCampaign extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = CampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return self::withFormErrors(fn () => app(SaveCampaign::class)->handle(null, $data));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Campaña creada';
    }
}
