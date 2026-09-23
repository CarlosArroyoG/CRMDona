<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditEvent: string implements HasColor, HasLabel
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Archived = 'archived';
    case Unarchived = 'unarchived';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case TagsChanged = 'tags_changed';
    case Deactivated = 'deactivated';
    case Reactivated = 'reactivated';
    case PasswordReset = 'password_reset';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Creación',
            self::Updated => 'Modificación',
            self::Deleted => 'Eliminación',
            self::Archived => 'Archivado',
            self::Unarchived => 'Reactivación',
            self::Confirmed => 'Confirmación',
            self::Cancelled => 'Cancelación',
            self::TagsChanged => 'Cambio de etiquetas',
            self::Deactivated => 'Desactivación de usuario',
            self::Reactivated => 'Reactivación de usuario',
            self::PasswordReset => 'Restablecimiento de contraseña',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Created, self::Confirmed, self::Reactivated, self::Unarchived => 'success',
            self::Deleted, self::Cancelled, self::Deactivated => 'danger',
            self::Archived => 'gray',
            self::PasswordReset => 'warning',
            self::Updated, self::TagsChanged => 'info',
        };
    }
}
