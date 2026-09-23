<?php

declare(strict_types=1);

use App\Http\Controllers\CfdiFileController;
use App\Http\Controllers\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Notificaciones de los proveedores de pago (sin sesión ni CSRF: se
// autentican con la firma de cada proveedor).
Route::post('/webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.payments');

// XML y PDF de CFDI (disco privado; permiso cfdi.view). Sesión del panel.
Route::get('/admin/cfdi-files/{cfdi}/{format}', CfdiFileController::class)
    ->whereIn('format', ['xml', 'pdf'])
    ->middleware('auth')
    ->name('cfdi.files');
