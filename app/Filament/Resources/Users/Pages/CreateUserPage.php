<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\CreateUser;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUserPage extends CreateRecord
{
    use ReportsActionErrors;

    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return self::withFormErrors(fn () => app(CreateUser::class)->handle(
            (string) $data['name'],
            (string) $data['email'],
            $data['role'] ?? null,
            (string) $data['password'],
            (string) $data['password_confirmation'],
        ));
    }

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Usuario creado';
    }
}
