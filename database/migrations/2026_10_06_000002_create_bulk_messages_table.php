<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envíos masivos informativos (docs/tecnico/carga-y-envios-masivos.md):
 *
 * - `bulk_messages`: el mensaje (texto simple con variables), los filtros de
 *   audiencia y su ciclo: borrador → preparando → enviado, o detenido. Exige
 *   un correo de prueba (`tested_at`) antes de enviar.
 * - `communications.bulk_message_id` y el tipo `bulk_message`: cada donante
 *   recibe un registro propio en el historial, con llave única por envío y
 *   donante (un reintento nunca duplica el correo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('subject', 200);
            $table->text('body');
            $table->jsonb('audience')->default('{}');
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('recipients_count')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->foreignId('tested_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('stopped_at')->nullable();
            $table->foreignId('stopped_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        foreach ([
            "check (status in ('draft', 'preparing', 'sent', 'stopped'))" => 'bulk_messages_status_valid',
            "check ((status = 'draft') = (sent_at is null))" => 'bulk_messages_sent_at',
            "check (status = 'draft' or (sent_by_id is not null and tested_at is not null))" => 'bulk_messages_sent_by_tested',
            "check ((status = 'stopped') = (stopped_at is not null))" => 'bulk_messages_stopped_at',
        ] as $check => $name) {
            DB::statement("alter table bulk_messages add constraint {$name} {$check}");
        }

        Schema::table('communications', function (Blueprint $table): void {
            $table->foreignId('bulk_message_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['bulk_message_id', 'status']);
        });

        DB::statement('alter table communications drop constraint communications_kind_valid');
        DB::statement("alter table communications add constraint communications_kind_valid check (kind in ('thank_you', 'cfdi', 'birthday', 'payment_request', 'bulk_message'))");
        DB::statement("alter table communications add constraint communications_bulk_message_reference check ((kind = 'bulk_message') = (bulk_message_id is not null))");
    }

    public function down(): void
    {
        // El historial de envíos es evidencia: no se revierte si ya hay correos masivos.
        if (DB::table('communications')->where('kind', 'bulk_message')->exists()) {
            throw new RuntimeException('No se revierte: el historial ya tiene correos de envíos masivos.');
        }

        DB::statement('alter table communications drop constraint communications_bulk_message_reference');
        DB::statement('alter table communications drop constraint communications_kind_valid');
        DB::statement("alter table communications add constraint communications_kind_valid check (kind in ('thank_you', 'cfdi', 'birthday', 'payment_request'))");

        Schema::table('communications', function (Blueprint $table): void {
            $table->dropIndex(['bulk_message_id', 'status']);
            $table->dropConstrainedForeignId('bulk_message_id');
        });

        Schema::dropIfExists('bulk_messages');
    }
};
