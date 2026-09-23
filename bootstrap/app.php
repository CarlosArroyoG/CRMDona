<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cabeceras de seguridad y CSP en todas las respuestas (panel incluido).
        $middleware->append(SecurityHeaders::class);
        // Los webhooks de pagos se autentican con la firma del proveedor.
        $middleware->preventRequestForgery(except: ['webhooks/payments/*']);
        // Rutas fuera del panel que exigen sesión (descargas de CFDI): al login de Filament.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
