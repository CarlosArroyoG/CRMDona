<?php

declare(strict_types=1);

/*
 * CFDI de donativos (Fase 3, docs/tecnico/fase-3-cfdi.md).
 *
 * El PAC real todavía no está elegido. Certificados (CSD), contraseñas y
 * llaves del PAC solo por variables de entorno o en el panel del PAC:
 * nunca en git, base de datos, seeders, pruebas ni logs.
 */
return [

    // PAC activo. Hoy solo "fake" (local y testing). Nulo = sin emisión de CFDI.
    'provider' => env('CFDI_PROVIDER'),

    // [F] Emitir automáticamente al confirmar un donativo con recibo deducible
    // solicitado. Apagado hasta la decisión fiscal (docs/tecnico/fase-3-cfdi.md §2).
    'auto_issue' => (bool) env('CFDI_AUTO_ISSUE', false),

    // Serie interna de los CFDI de donativos (el folio es el id del CFDI en el CRM).
    'series' => env('CFDI_SERIES', 'DON'),

    // Disco privado para XML y PDF (storage/app/private; volumen crm-storage en Coolify).
    'disk' => env('CFDI_DISK', 'local'),

    'stamping' => [
        'tries' => 5,
        'backoff' => [30, 120, 600, 1800],
        // Un timbrado que sigue "Timbrando" después de esto se reintenta con la misma llave.
        'stuck_after_minutes' => 15,
    ],

    'fake' => [
        'store' => env('CFDI_FAKE_STORE', 'array'),
    ],
];
