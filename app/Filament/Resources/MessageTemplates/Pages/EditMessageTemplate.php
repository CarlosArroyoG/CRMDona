<?php

declare(strict_types=1);

namespace App\Filament\Resources\MessageTemplates\Pages;

use App\Actions\Communications\UpdateMessageTemplate;
use App\Communications\MessageComposer;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\MessageTemplate;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class EditMessageTemplate extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = MessageTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label('Vista previa')->icon(Heroicon::OutlinedEye)->color('gray')
                ->modalHeading('Vista previa con datos de ejemplo')
                ->modalSubmitAction(false)->modalCancelActionLabel('Cerrar')
                ->modalContent(function (): View {
                    /** @var MessageTemplate $record */
                    $record = $this->getRecord();
                    /** @var array{subject?: string, body?: string} $data */
                    $data = $this->data ?? [];

                    return view('filament.message-template-preview', app(MessageComposer::class)
                        ->preview($record->kind, (string) ($data['subject'] ?? $record->subject), (string) ($data['body'] ?? $record->body)));
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MessageTemplate $record */
        /** @var User $actor */
        $actor = auth()->user();

        return self::withFormErrors(fn () => app(UpdateMessageTemplate::class)->handle($record, $data, $actor));
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Plantilla guardada';
    }
}
