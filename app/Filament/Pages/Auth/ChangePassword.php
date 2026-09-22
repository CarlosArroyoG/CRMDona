<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Cada usuario cambia su propia contraseña (política de ADR-002: mínimo 12
 * caracteres con letras y números). Nombre, correo y rol solo los cambia el
 * Administrador desde Usuarios.
 */
class ChangePassword extends EditProfile
{
    public static function getLabel(): string
    {
        return 'Cambiar contraseña';
    }

    public function form(Schema $schema): Schema
    {
        $password = $this->getPasswordFormComponent();
        if ($password instanceof TextInput) {
            $password->required()->helperText('Mínimo 12 caracteres, con letras y números.');
        }

        return $schema->components([
            $this->getCurrentPasswordFormComponent()->visible(),
            $password,
            $this->getPasswordConfirmationFormComponent(),
        ]);
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Contraseña actualizada';
    }
}
