<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Seguridad de la conexión SMTP, tal como la soporta Symfony Mailer:
 * STARTTLS obligatorio (típico en 587), TLS implícito / SMTPS (típico en 465)
 * o sin cifrado (solo para relays internos de confianza).
 */
enum MailEncryption: string implements HasLabel
{
    case StartTls = 'starttls';
    case Tls = 'tls';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::StartTls => 'TLS / STARTTLS (típico: puerto 587)',
            self::Tls => 'SSL / TLS implícito (típico: puerto 465)',
            self::None => 'Ninguno (solo servidores internos de confianza)',
        };
    }
}
