<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Procedencia del cambio en la bitácora (user, webhook, job, synchronization,
 * console). Sin usuario "sistema": un cambio automático queda con
 * user_id nulo y su procedencia aquí. El detalle técnico (qué evento o
 * recurso) vive en webhook_events y en las tablas de pagos, no aquí.
 * Nulo en los registros anteriores a la Fase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La tabla es de solo inserción por trigger; agregar una columna
        // nula no modifica filas, así que el trigger no interviene.
        DB::statement('alter table audit_logs add column source varchar(20) null');
        DB::statement("alter table audit_logs add constraint audit_logs_source_valid
            check (source is null or source in ('user', 'webhook', 'job', 'synchronization', 'console'))");
    }

    public function down(): void
    {
        DB::statement('alter table audit_logs drop column source');
    }
};
