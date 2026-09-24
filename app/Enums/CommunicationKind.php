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
 * La solicitud de pago también es transaccional: es un correo individual con
 * el enlace de una solicitud concreta que el personal preparó para ese
 * donante (docs/tecnico/solicitudes-de-pago.md). No es un envío masivo ni de
 * campaña: siempre nace de una solicitud y de una persona que lo pide.
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
    case PaymentRequest = 'payment_request';

    /**
     * Tipos que el CRM todavía envía.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::ThankYou, self::Birthday, self::PaymentRequest];
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
            self::PaymentRequest => [
                'importe' => 'Importe solicitado, con formato (ej. $1,500.00 MXN)',
                'frecuencia' => '"una sola vez" o "cada mes"',
                'destino' => 'Campaña, programa o "el fondo general"',
                'vigencia' => 'Fecha hasta la que el enlace es válido',
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
            self::PaymentRequest => 'Tu donativo a {{ organizacion }}: enlace de pago seguro',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::ThankYou => "Hola, {{ nombre }}:\n\nGracias por tu donativo de {{ importe }} recibido el {{ fecha_donativo }} para {{ destino }}. Tu apoyo hace posible nuestro trabajo.\n\nCon gratitud,\n{{ organizacion }}",
            self::Cfdi => '',
            self::Birthday => "Hola, {{ nombre }}:\n\nEn {{ organizacion }} te deseamos un muy feliz cumpleaños. Gracias por ser parte de nuestra comunidad.",
            self::PaymentRequest => "Hola, {{ nombre }}:\n\nGracias por tu interés en apoyar a {{ organizacion }}. Preparamos tu donativo de {{ importe }} ({{ frecuencia }}) para {{ destino }}.\n\nPara completarlo, usa el botón de este correo. El pago se hace en la página segura del proveedor de pago: nosotros no vemos ni guardamos los datos de tu tarjeta.\n\nEl enlace es válido hasta el {{ vigencia }}. Si no reconoces esta solicitud, ignora este correo.",
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::ThankYou => 'Agradecimiento por donativo',
            self::Cfdi => 'Envío de CFDI (histórico)',
            self::Birthday => 'Felicitación de cumpleaños',
            self::PaymentRequest => 'Solicitud de pago (enlace)',
        };
    }
}
