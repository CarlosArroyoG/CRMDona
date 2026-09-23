<?php

declare(strict_types=1);

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
