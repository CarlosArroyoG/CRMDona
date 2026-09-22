<?php

declare(strict_types=1);

namespace App\Filament\Resources\Campaigns\Pages;

use App\Actions\Campaigns\SaveCampaign;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Models\Campaign;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCampaign extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            CampaignResource::deleteAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Campaign $record */
        return self::withFormErrors(fn () => app(SaveCampaign::class)->handle($record, $data));
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
