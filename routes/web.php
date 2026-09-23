<?php

declare(strict_types=1);

use App\Http\Controllers\CfdiFileController;
use App\Http\Controllers\DonationReceiptFileController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\UnsubscribeController;
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

// Recibo simple (disco privado; permiso receipts.view). Sesión del panel.
Route::get('/admin/receipt-files/{receipt}', DonationReceiptFileController::class)
    ->middleware('auth')
    ->name('receipts.file');

// Baja de comunicaciones desde el correo, sin sesión: token aleatorio de 64 hex.
Route::controller(UnsubscribeController::class)
    ->prefix('/comunicaciones/baja/{token}')
    ->where(['token' => '[a-f0-9]{64}'])
    ->middleware('throttle:30,1')
    ->group(function (): void {
        Route::get('/', 'show')->name('communications.unsubscribe');
        Route::post('/', 'store')->name('communications.unsubscribe.store');
    });
