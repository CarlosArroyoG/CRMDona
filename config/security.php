<?php

declare(strict_types=1);

/*
 * Cabeceras de seguridad (Fase 7, docs/tecnico/seguridad.md).
 */
return [

    'csp' => [
        // enforce | report-only | off. Por defecto: enforce en producción y testing;
        // report-only en local (no estorba al servidor de Vite).
        'mode' => env('CSP_MODE', in_array(env('APP_ENV'), ['production', 'testing'], true) ? 'enforce' : 'report-only'),

        // Orígenes externos de la página pública (proveedores de pago previstos). [S] confirmar en sus sandbox.
        'payment_sources' => [
            'script' => ['https://js.stripe.com', 'https://sdk.mercadopago.com', 'https://http2.mlstatic.com'],
            'frame' => ['https://js.stripe.com', 'https://hooks.stripe.com', 'https://checkout.stripe.com', 'https://*.mercadopago.com', 'https://*.mercadolibre.com'],
            'connect' => ['https://api.stripe.com', 'https://checkout.stripe.com', 'https://api.mercadopago.com', 'https://*.mercadopago.com', 'https://*.mercadolibre.com', 'https://*.mlstatic.com'],
            'img' => ['https://*.stripe.com', 'https://http2.mlstatic.com', 'https://*.mercadopago.com'],
        ],
    ],

    // HSTS: solo en producción y si la petición llegó por HTTPS (detrás del proxy de Coolify).
    'hsts' => [
        'enabled' => (bool) env('HSTS_ENABLED', true),
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
    ],

    // Emergencia (rollback de un despliegue fallido): permite los comandos destructivos
    // aunque la base no sea desechable. Solo temporalmente, con respaldo verificado.
    'allow_destructive_commands' => (bool) env('ALLOW_DESTRUCTIVE_DB_COMMANDS', false),

    // Bases donde se permiten comandos destructivos (migrate:fresh, db:wipe, rollback…).
    // `crm` (datos de desarrollo) y producción nunca.
    'destructive_databases' => ['crm_testing', 'crm_validation', 'crm_restore_test'],
];
