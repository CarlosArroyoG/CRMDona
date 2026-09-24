<?php

declare(strict_types=1);

use App\Http\Controllers\DonationReceiptFileController;
use App\Http\Controllers\ExternalCfdiFileController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PublicDonationController;
use App\Http\Controllers\UnsubscribeController;
use Illuminate\Support\Facades\Route;

// La raíz lleva a la página pública de donativos.
Route::redirect('/', '/donar');

// Página pública de donativos (Fase 6). Envíos con CSRF y límite por IP.
// El token de sesión es aleatorio y solo sirve en el navegador que lo creó.
Route::controller(PublicDonationController::class)->prefix('/donar')->name('donate.')->group(function (): void {
    Route::get('/', 'create')->name('create');
    Route::post('/', 'store')->middleware('throttle:public-donations')->name('store');
    Route::get('/gracias', 'returned')->name('returned');
    Route::get('/campana/{campaign}', 'create')->where('campaign', '[a-z0-9\-]{1,120}')->name('campaign');
    Route::post('/campana/{campaign}', 'store')->where('campaign', '[a-z0-9\-]{1,120}')->middleware('throttle:public-donations')->name('campaign.store');
    Route::get('/resumen/{token}', 'summary')->where('token', '[A-Za-z0-9]{40}')->name('summary');
    Route::post('/pagar/{token}', 'pay')->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:public-donations')->name('pay');
    Route::get('/estado/{token}', 'status')->where('token', '[A-Za-z0-9]{40}')->name('status');
    Route::post('/reintentar/{token}', 'retry')->where('token', '[A-Za-z0-9]{40}')->middleware('throttle:public-donations')->name('retry');
});

// Notificaciones de los proveedores de pago (sin sesión ni CSRF: se
// autentican con la firma de cada proveedor).
Route::post('/webhooks/payments/{provider}', PaymentWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.payments');

// XML y PDF de CFDI externos (disco privado; permiso cfdi.view). Sesión del panel.
Route::get('/admin/external-cfdi-files/{externalCfdi}/{format}', ExternalCfdiFileController::class)
    ->whereIn('format', ['xml', 'pdf'])
    ->middleware('auth')
    ->name('external-cfdi.files');

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
