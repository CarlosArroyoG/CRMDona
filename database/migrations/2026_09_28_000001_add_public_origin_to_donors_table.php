<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6 — página pública de donativos. Un donante puede registrarse a sí
 * mismo al donar: no hay usuario que lo capture y no existe un usuario
 * "sistema" (mismo criterio que donations.origin). `origin` distingue el
 * registro manual del de la página pública; la procedencia queda además en
 * audit_logs.source = 'donor'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("alter table donors add column origin varchar(20) not null default 'manual'");
        DB::statement('alter table donors alter column registered_by_id drop not null');
        DB::statement("alter table donors add constraint donors_origin_valid check (origin in ('manual', 'public_page'))");
        DB::statement("alter table donors add constraint donors_origin_actor check ((origin = 'manual') = (registered_by_id is not null))");
    }

    public function down(): void
    {
        if (DB::table('donors')->where('origin', 'public_page')->exists()) {
            throw new RuntimeException('No se revierte: ya hay donantes registrados desde la página pública.');
        }

        DB::statement('alter table donors drop constraint donors_origin_actor');
        DB::statement('alter table donors drop constraint donors_origin_valid');
        DB::statement('alter table donors alter column registered_by_id set not null');
        DB::statement('alter table donors drop column origin');
    }
};
