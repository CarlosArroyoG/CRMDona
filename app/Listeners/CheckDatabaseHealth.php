<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

/**
 * `/up` (health check de Coolify) también comprueba que la aplicación llega
 * a PostgreSQL. Si la consulta falla, `/up` responde 500 y el despliegue no
 * queda sano. No expone detalles: Laravel solo muestra que falló.
 */
class CheckDatabaseHealth
{
    public function handle(DiagnosingHealth $event): void
    {
        DB::select('select 1');
    }
}
