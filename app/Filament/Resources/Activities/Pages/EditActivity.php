<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Pages;

use App\Actions\Activities\UpdateActivity;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\DonorActivity;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Solo accesible mientras la actividad está "Programada" (DonorActivityPolicy).
 */
class EditActivity extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var DonorActivity $record */
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(UpdateActivity::class)->handle($record, $data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return ActivityResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
