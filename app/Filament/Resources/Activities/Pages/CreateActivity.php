<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Pages;

use App\Actions\Activities\CreateActivity as CreateActivityAction;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Activities\ActivityResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateActivity extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = ActivityResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(CreateActivityAction::class)->handle($data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return ActivityResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Actividad registrada';
    }
}
