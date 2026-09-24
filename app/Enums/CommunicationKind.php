<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipos de correo a donantes (Fase 4). El agradecimiento es transaccional: se
 * refiere a un donativo concreto y se envía aunque el donante no acepte
 * comunicaciones (config `communications.transactional_requires_consent`).
 * La felicitación de cumpleaños es informativa: exige consentimiento vigente
 * y lleva enlace de baja.
 *
 * `Cfdi` es histórico: el CRM ya no emite ni envía CFDI
 * (docs/tecnico/cfdi-externo.md). Se conserva solo para leer los registros
 * anteriores; no se encola, no se reenvía y no tiene plantilla.
 */
enum CommunicationKind: string implements HasLabel
{
    case ThankYou = 'thank_you';
    case Cfdi = 'cfdi';
    case Birthday = 'birthday';

    /**
     * Tipos que el CRM todavía envía.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::ThankYou, self::Birthday];
    }

    public function isHistorical(): bool
    {
        return $this === self::Cfdi;
    }

    public function requiresConsent(): bool
    {
        return $this === self::Birthday || (bool) config('communications.transactional_requires_consent');
    }

    /**
     * Variables que admite la plantilla ({{ variable }}), con su descripción.
     *
     * @return array<string, string>
     */
    public function variables(): array
    {
        $common = [
            'nombre' => 'Nombre del donante (nombre de pila, o razón social si es persona moral)',
            'organizacion' => 'Nombre de la organización',
        ];

        return $common + match ($this) {
            self::ThankYou => [
                'importe' => 'Importe del donativo, con formato (ej. $1,500.00 MXN)',
                'fecha_donativo' => 'Fecha en que se recibió el donativo',
                'destino' => 'Campaña, programa o "fondo general"',
                'folio_recibo' => 'Folio del recibo simple',
            ],
            self::Cfdi, self::Birthday => [],
        };
    }

    public function defaultSubject(): string
    {
        return match ($this) {
            self::ThankYou => 'Gracias por tu donativo, {{ nombre }}',
            self::Cfdi => 'Comprobante fiscal (histórico)',
            self::Birthday => '¡Feliz cumpleaños, {{ nombre }}!',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::ThankYou => "Hola, {{ nombre }}:\n\nGracias por tu donativo de {{ importe }} recibido el {{ fecha_donativo }} para {{ destino }}. Tu apoyo hace posible nuestro trabajo.\n\nCon gratitud,\n{{ organizacion }}",
            self::Cfdi => '',
            self::Birthday => "Hola, {{ nombre }}:\n\nEn {{ organizacion }} te deseamos un muy feliz cumpleaños. Gracias por ser parte de nuestra comunidad.",
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::ThankYou => 'Agradecimiento por donativo',
            self::Cfdi => 'Envío de CFDI (histórico)',
            self::Birthday => 'Felicitación de cumpleaños',
        };
    }
}
