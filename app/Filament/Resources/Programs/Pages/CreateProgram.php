<?php

declare(strict_types=1);

namespace App\Filament\Resources\Programs\Pages;

use App\Actions\Programs\SaveProgram;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Programs\ProgramResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProgram extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = ProgramResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return self::withFormErrors(fn () => app(SaveProgram::class)->handle(null, $data));
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Programa creado';
    }
}
