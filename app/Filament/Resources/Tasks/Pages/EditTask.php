<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tasks\Pages;

use App\Actions\Tasks\UpdateTask;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Solo accesible mientras la tarea está abierta (TaskPolicy).
 */
class EditTask extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Task $record */
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(UpdateTask::class)->handle($record, $data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
