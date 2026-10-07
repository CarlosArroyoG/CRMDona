<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Canal de una interacción registrada manualmente con un donante
 * (docs/tecnico/gestion-relaciones-donantes.md). No sustituye a
 * `Communication`: esto es lo que el personal registra a mano, nunca un
 * correo o WhatsApp que ya envía el sistema.
 */
enum ActivityType: string implements HasLabel
{
    case Call = 'call';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case Meeting = 'meeting';
    case Visit = 'visit';
    case FollowUp = 'follow_up';
    case Note = 'note';
    case Proposal = 'proposal';
    case Thanks = 'thanks';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Call => 'Llamada',
            self::WhatsApp => 'WhatsApp',
            self::Email => 'Correo',
            self::Meeting => 'Reunión',
            self::Visit => 'Visita',
            self::FollowUp => 'Seguimiento',
            self::Note => 'Nota',
            self::Proposal => 'Propuesta',
            self::Thanks => 'Agradecimiento',
            self::Other => 'Otra',
        };
    }
}
