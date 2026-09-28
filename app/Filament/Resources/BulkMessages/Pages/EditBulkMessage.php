<?php

declare(strict_types=1);

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Actions\Communications\SaveBulkMessage;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Models\BulkMessage;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBulkMessage extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = BulkMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var BulkMessage $message */
        $message = $this->getRecord();

        return [...$data, ...$message->audience()->toArray()];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var BulkMessage $record */
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(SaveBulkMessage::class)->handle($record, $data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return BulkMessageResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
