<?php

declare(strict_types=1);

/*
 * CFDI de donativos (Fase 3, docs/tecnico/fase-3-cfdi.md).
 *
 * Certificados (CSD), contraseñas y llaves del PAC solo por variables de
 * entorno o en el panel del PAC: nunca en git, base de datos, seeders,
 * pruebas ni logs.
 */
return [

    // PAC activo: "facturapi" o "fake" (solo local y testing). Nulo = sin emisión de CFDI.
    'provider' => env('CFDI_PROVIDER'),

    // [V] La donataria debe expedir CFDI por los donativos que recibe, dentro de
    // 24 horas: se emite al confirmar el donativo, sin depender de que el donante
    // lo pida. Solo actúa si hay PAC configurado.
    'auto_issue' => (bool) env('CFDI_AUTO_ISSUE', true),

    // La conciliación vuelve a evaluar los donativos confirmados en estas horas que siguen sin CFDI.
    'sweep_hours' => (int) env('CFDI_SWEEP_HOURS', 72),

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

    'facturapi' => [
        // sk_test_… (pruebas, sin validez fiscal) o sk_live_… (solo en producción).
        'key' => env('FACTURAPI_KEY'),
        'base_url' => env('FACTURAPI_BASE_URL', 'https://www.facturapi.io/v2'),
        'timeout' => (int) env('FACTURAPI_TIMEOUT', 60),
        'connect_timeout' => 10,
    ],

    'fake' => [
        'store' => env('CFDI_FAKE_STORE', 'array'),
    ],
];
