<?php

declare(strict_types=1);

namespace App\Filament\Resources\Programs\Pages;

use App\Actions\Programs\SaveProgram;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Programs\ProgramResource;
use App\Models\Program;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProgram extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = ProgramResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            ProgramResource::deleteAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Program $record */
        return self::withFormErrors(fn () => app(SaveProgram::class)->handle($record, $data));
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
