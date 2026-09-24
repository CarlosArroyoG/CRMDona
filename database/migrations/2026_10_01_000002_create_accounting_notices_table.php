<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flujo contable (docs/tecnico/cfdi-externo.md): cada donativo confirmado
 * genera un aviso a Contabilidad, que emite los CFDI fuera del CRM.
 *
 * - `accounting_notices`: uno por donativo (idempotencia), con el estado del
 *   envío del aviso y, aparte, el procesamiento contable (lo marca una
 *   persona). No guarda datos fiscales: el correo se arma al enviarlo.
 * - `users.receives_accounting_notices`: el Administrador elige quién de
 *   Contabilidad recibe los avisos (solo roles con `accounting.process`).
 * - Los donativos ya confirmados reciben su registro como "No enviado" (no
 *   se manda un correo retroactivo) y pendientes de procesamiento contable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('receives_accounting_notices')->default(false);
        });

        Schema::create('accounting_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('delivered_to')->default('[]');
            $table->string('skip_reason', 500)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('processing_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'status']);
        });

        foreach ([
            "check (status in ('pending', 'sending', 'sent', 'failed', 'skipped'))" => 'accounting_notices_status_valid',
            "check ((status = 'sent') = (sent_at is not null))" => 'accounting_notices_sent_at',
            "check (status <> 'skipped' or skip_reason is not null)" => 'accounting_notices_skip_reason',
            'check ((processed_at is null) = (processed_by_id is null))' => 'accounting_notices_processed_by',
        ] as $check => $name) {
            DB::statement("alter table accounting_notices add constraint {$name} {$check}");
        }

        $now = now();
        DB::statement("insert into accounting_notices (donation_id, status, skip_reason, created_at, updated_at)
            select id, 'skipped', 'Donativo confirmado antes de que existiera el aviso a Contabilidad.', ?, ?
            from donations where status = 'confirmed'", [$now, $now]);
    }

    public function down(): void
    {
        // Revertir borraría el trabajo de Contabilidad: solo si nadie procesó nada aún.
        if (DB::table('accounting_notices')->whereNotNull('processed_at')->exists()) {
            throw new RuntimeException('Hay donativos marcados como procesados por Contabilidad; revertir borraría ese registro.');
        }

        Schema::dropIfExists('accounting_notices');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('receives_accounting_notices');
        });
    }
};
