<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Cada usuario cambia su propia contraseña (política de ADR-002: mínimo 12
 * caracteres con letras y números). Nombre, correo y rol solo los cambia el
 * Administrador desde Usuarios. Si la contraseña actual es temporal, esta es
 * la única pantalla disponible hasta cambiarla (EnsurePasswordIsCurrent).
 */
class ChangePassword extends EditProfile
{
    public static function getLabel(): string
    {
        return 'Cambiar contraseña';
    }

    public function getSubheading(): ?string
    {
        $user = $this->getUser();

        return $user instanceof User && $user->mustChangePassword()
            ? 'Tu contraseña es temporal. Para continuar, escríbela en "Contraseña actual" y elige una nueva que solo tú conozcas.'
            : null;
    }

    public function form(Schema $schema): Schema
    {
        $password = $this->getPasswordFormComponent();
        if ($password instanceof TextInput) {
            $password->required()
                ->different('currentPassword')
                ->helperText('Mínimo 12 caracteres, con letras y números. Debe ser distinta de la actual.');
        }

        return $schema->components([
            $this->getCurrentPasswordFormComponent()->visible(),
            $password,
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    /**
     * Al fijar una contraseña propia deja de ser temporal (en el mismo
     * guardado, para un solo registro en la bitácora).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if ($record instanceof User && filled($data['password'] ?? null)) {
            $record->forceFill(['password_change_required_at' => null]);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Contraseña actualizada';
    }
}
