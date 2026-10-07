<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Actions\Tasks\CreateTask as CreateTaskAction;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTask extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = TaskResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(CreateTaskAction::class)->handle($data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Tarea creada';
    }
}
