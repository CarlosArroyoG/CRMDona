<?php

declare(strict_types=1);

namespace App\Filament\Resources\BulkMessages\Pages;

use App\Actions\Communications\SaveBulkMessage;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBulkMessage extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = BulkMessageResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(SaveBulkMessage::class)->handle(null, $data, $actor));
    }

    protected function getRedirectUrl(): string
    {
        return BulkMessageResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Borrador guardado. Manda el correo de prueba antes de enviar.';
    }
}
