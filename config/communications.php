<?php

declare(strict_types=1);

/*
 * Comunicaciones con donantes (Fase 4, docs/tecnico/fase-4-comunicaciones.md).
 *
 * El proveedor de correo real se configura solo con las variables MAIL_* de
 * Laravel (SMTP u otro mailer nativo). Nunca credenciales en git.
 */
return [

    // Agradecimiento y CFDI se refieren a un donativo concreto (transaccionales).
    // true = también exigen "Acepta recibir comunicaciones".
    'transactional_requires_consent' => (bool) env('COMMUNICATIONS_TRANSACTIONAL_REQUIRES_CONSENT', false),

    // Espera antes de enviar el agradecimiento, para adjuntar el CFDI si se timbra
    // pronto. Si no está listo, el agradecimiento sale igual y el CFDI después.
    'thank_you_delay_seconds' => (int) env('COMMUNICATIONS_THANK_YOU_DELAY', 300),

    // Felicitaciones: todos los días a esta hora (zona de la organización).
    'birthday_time' => '09:00',
    'timezone' => 'America/Mexico_City',

    // Recibos simples: disco privado (storage/app/private).
    'disk' => env('COMMUNICATIONS_DISK', 'local'),

    'sending' => [
        'tries' => 3,
        'backoff' => [60, 300, 1800],
        // Un envío que sigue "Enviando" después de esto se puede retomar.
        'stuck_after_minutes' => 15,
    ],
];
