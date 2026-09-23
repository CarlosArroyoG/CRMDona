<?php

declare(strict_types=1);

/*
 * Página pública de donativos (Fase 6, docs/tecnico/fase-6-pagina-publica.md).
 * Los límites de importe viven en Organización (y los del proveedor); aquí
 * solo lo propio de la página.
 */
return [

    'public' => [
        // Cantidades sugeridas en MXN (decisión reversible; se ajustan por entorno).
        'suggested_amounts' => array_values(array_filter(array_map('trim', explode(',', (string) env('DONATIONS_SUGGESTED_AMOUNTS', '200,500,1000,2000'))))),

        // Proveedor de la página. Vacío = el primero habilitado (Stripe, Mercado Pago; FakeGateway solo en local).
        'provider' => env('DONATIONS_PUBLIC_PROVIDER'),

        // Anti-spam sin dependencias: segundos mínimos entre abrir el formulario y enviarlo.
        'min_seconds_to_submit' => (int) env('DONATIONS_MIN_SECONDS_TO_SUBMIT', 3),

        // Intentos por minuto por IP en los envíos.
        'rate_limit_per_minute' => (int) env('DONATIONS_RATE_LIMIT', 10),

        // Colores de la identidad (configurables).
        'colors' => [
            'primary' => env('BRAND_PRIMARY_COLOR', '#162562'),
            'secondary' => env('BRAND_SECONDARY_COLOR', '#FF9D2F'),
        ],
    ],
];
