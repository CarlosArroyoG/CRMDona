<?php

declare(strict_types=1);

/*
 * Comunicaciones con donantes (Fase 4, docs/tecnico/fase-4-comunicaciones.md).
 *
 * El proveedor de correo real se configura solo con las variables MAIL_* de
 * Laravel (SMTP u otro mailer nativo). Nunca credenciales en git.
 */
return [

    // El agradecimiento se refiere a un donativo concreto (transaccional).
    // true = también exige "Acepta recibir comunicaciones".
    'transactional_requires_consent' => (bool) env('COMMUNICATIONS_TRANSACTIONAL_REQUIRES_CONSENT', false),

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
