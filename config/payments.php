<?php

declare(strict_types=1);

/*
 * Pagos en línea (Fase 2, docs/tecnico/fase-2-diseno-pagos.md).
 *
 * Los secretos solo llegan por variables de entorno: nunca se escriben en
 * la base de datos, el repositorio, los seeders, las pruebas ni los logs.
 * Cada proveedor se habilita por separado; no hay una pasarela global fija.
 */
return [

    'providers' => [

        'stripe' => [
            'enabled' => (bool) env('STRIPE_ENABLED', false),
            // test | live. Debe coincidir con el prefijo de la llave (sk_test_ / sk_live_).
            'mode' => env('STRIPE_MODE', 'test'),
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            // Tolerancia de la firma del webhook, en segundos (valor predeterminado de la biblioteca oficial).
            'webhook_tolerance' => 300,
        ],

        'mercado_pago' => [
            'enabled' => (bool) env('MERCADO_PAGO_ENABLED', false),
            // test | live. Mercado Pago no distingue el modo por el prefijo de la credencial: se declara aquí.
            'mode' => env('MERCADO_PAGO_MODE', 'test'),
            'access_token' => env('MERCADO_PAGO_ACCESS_TOKEN'),
            'public_key' => env('MERCADO_PAGO_PUBLIC_KEY'),
            'webhook_secret' => env('MERCADO_PAGO_WEBHOOK_SECRET'),
            'base_url' => env('MERCADO_PAGO_BASE_URL', 'https://api.mercadopago.com'),
            'timeout' => 20,
        ],

        // Pasarela simulada: solo en local y testing, nunca en producción.
        'fake' => [
            'enabled' => (bool) env('PAYMENTS_FAKE_ENABLED', false),
            // Almacén de caché del estado simulado ("file" lo comparte entre procesos).
            'store' => env('PAYMENTS_FAKE_STORE', 'array'),
            'webhook_secret' => env('PAYMENTS_FAKE_WEBHOOK_SECRET', 'fake-gateway-signing-key'),
            'min_amount' => null,
            'max_amount' => null,
        ],
    ],

    // URL a la que regresa el donante después del checkout (página pública, fase posterior).
    'return_url' => env('PAYMENTS_RETURN_URL', env('APP_URL').'/donar/gracias'),

    'webhooks' => [
        // Intentos del Job que procesa cada notificación antes de marcarla fallida.
        'tries' => 5,
        'backoff' => [10, 60, 300, 900],
    ],

    'reconciliation' => [
        // Minutos que espera la conciliación antes de volver a consultar al proveedor.
        'stale_after_minutes' => 60,
        // Reembolsos sin respuesta del proveedor (por ejemplo, timeout) que se reenvían con la misma llave.
        'refund_resubmit_after_minutes' => 5,
        // Si el proveedor sigue sin responder después de este tiempo se abre una incidencia.
        'refund_unavailable_alert_after_minutes' => 60,
    ],
];
