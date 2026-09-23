<?php

declare(strict_types=1);

use App\Jobs\ReconcileCfdis;
use App\Jobs\ReconcilePayments;
use App\Models\Export;
use Illuminate\Support\Facades\Schedule;

// Archivos de exportación: se eliminan a los 7 días (ADR-007). Solo el
// archivo temporal; los datos originales nunca se borran por antigüedad.
Schedule::command('model:prune', ['--model' => [Export::class]])->dailyAt('03:00');

// Conciliación de pagos (fase-2-diseno-pagos.md §21): reembolsos sin
// respuesta, pagos que siguen en proceso y pagos exitosos sin donativo.
Schedule::job(new ReconcilePayments)->everyFifteenMinutes();

// CFDI (Fase 3): timbrados interrumpidos, errores temporales y cancelaciones en espera.
Schedule::job(new ReconcileCfdis)->everyFifteenMinutes();
