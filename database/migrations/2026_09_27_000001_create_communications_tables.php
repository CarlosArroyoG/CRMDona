<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — comunicaciones (docs/tecnico/fase-4-comunicaciones.md):
 *
 * - `donation_receipts`: recibo simple (acuse), no fiscal; uno por donativo.
 * - `message_templates`: textos editables por tipo de mensaje.
 * - `communications`: registro de cada envío; `dedupe_key` única evita
 *   agradecimientos o felicitaciones repetidas por reintentos.
 * - `donors.communications_token`: token no predecible para la baja sin sesión.
 * - `audit_logs.source` admite `donor` (baja desde el enlace del correo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('folio', 30)->nullable()->unique();
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30)->unique();
            $table->string('subject', 200);
            $table->text('body');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('communications', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('donation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cfdi_id')->nullable()->constrained('cfdis')->restrictOnDelete();
            $table->string('dedupe_key', 150)->unique();
            $table->string('status', 20)->index();
            $table->string('recipient', 255)->nullable();
            $table->string('subject', 200)->nullable();
            $table->jsonb('attachments')->default('[]');
            $table->boolean('used_fallback_template')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('skip_reason', 255)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['donor_id', 'kind']);
            $table->index('donation_id');
        });

        foreach ([
            "check (kind in ('thank_you', 'cfdi', 'birthday'))" => 'communications_kind_valid',
            "check (status in ('queued', 'sending', 'sent', 'failed', 'bounced', 'skipped'))" => 'communications_status_valid',
            "check (status <> 'sent' or sent_at is not null)" => 'communications_sent_at',
            "check (status <> 'skipped' or skip_reason is not null)" => 'communications_skip_reason',
            "check (kind <> 'thank_you' or donation_id is not null)" => 'communications_thank_you_donation',
            "check (kind <> 'cfdi' or cfdi_id is not null)" => 'communications_cfdi_reference',
        ] as $check => $name) {
            DB::statement("alter table communications add constraint {$name} {$check}");
        }
        DB::statement("alter table message_templates add constraint message_templates_kind_valid check (kind in ('thank_you', 'cfdi', 'birthday'))");

        Schema::table('donors', function (Blueprint $table): void {
            $table->string('communications_token', 64)->nullable()->unique();
        });

        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->boolean('thank_you_emails_enabled')->default(true);
            $table->boolean('birthday_emails_enabled')->default(true);
        });

        DB::statement('alter table audit_logs drop constraint audit_logs_source_valid');
        DB::statement("alter table audit_logs add constraint audit_logs_source_valid
            check (source is null or source in ('user', 'webhook', 'job', 'synchronization', 'console', 'donor'))");
    }

    public function down(): void
    {
        if (DB::table('audit_logs')->where('source', 'donor')->exists()) {
            throw new RuntimeException('No se revierte: la bitácora ya tiene bajas registradas por donantes (solo inserción).');
        }

        DB::statement('alter table audit_logs drop constraint audit_logs_source_valid');
        DB::statement("alter table audit_logs add constraint audit_logs_source_valid
            check (source is null or source in ('user', 'webhook', 'job', 'synchronization', 'console'))");

        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->dropColumn(['thank_you_emails_enabled', 'birthday_emails_enabled']);
        });
        Schema::table('donors', function (Blueprint $table): void {
            $table->dropColumn('communications_token');
        });
        Schema::dropIfExists('communications');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('donation_receipts');
    }
};
