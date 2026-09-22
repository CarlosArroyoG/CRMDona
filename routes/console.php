<?php

declare(strict_types=1);

use App\Models\Export;
use Illuminate\Support\Facades\Schedule;

// Archivos de exportación: se eliminan a los 7 días (ADR-007). Solo el
// archivo temporal; los datos originales nunca se borran por antigüedad.
Schedule::command('model:prune', ['--model' => [Export::class]])->dailyAt('03:00');
