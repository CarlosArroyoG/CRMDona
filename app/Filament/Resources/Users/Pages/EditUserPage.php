<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Actions\Users\SetPaymentAlertPreference;
use App\Actions\Users\UpdateUser;
use App\Filament\Concerns\ReportsActionErrors;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUserPage extends EditRecord
{
    use ReportsActionErrors;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            UserResource::toggleActiveAction(),
            UserResource::resetPasswordAction(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $user = self::withFormErrors(fn () => app(UpdateUser::class)->handle(
            $record,
            (string) $data['name'],
            (string) $data['email'],
            $data['role'] ?? null,
        ));

        /** @var User $actor */
        $actor = auth()->user();

        return app(SetPaymentAlertPreference::class)->handle($user, (bool) ($data['receives_payment_alerts'] ?? false), $actor);
    }

    protected function getRedirectUrl(): string
    {
        return UserResource::getUrl('index');
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Cambios guardados';
    }
}
