<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CFDI de donativos (Fase 3, docs/tecnico/fase-3-cfdi.md). Donation 1 → N
 * Cfdi: como máximo uno "vigente" (no cancelado) por donativo. Los datos
 * fiscales del emisor y del receptor NO se copian aquí: viven en
 * organization_settings y donor_tax_profiles, y lo emitido queda en el XML
 * timbrado (almacenamiento privado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cfdis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donation_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('external_id', 100)->nullable();
            $table->uuid('uuid')->nullable();
            $table->string('series', 25)->nullable();
            $table->string('folio', 40)->nullable();
            $table->string('status', 30)->index();
            $table->decimal('total', 12, 2);
            $table->char('currency', 3)->default('MXN');
            $table->string('idempotency_key', 255)->unique();
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 100)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('stamped_at')->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('cancellation_motive', 2)->nullable();
            $table->uuid('cancellation_replacement_uuid')->nullable();
            $table->string('cancellation_reason', 1000)->nullable();
            $table->timestamp('cancellation_requested_at')->nullable();
            $table->foreignId('cancellation_requested_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_provider_status', 50)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('donation_id');
        });

        foreach ([
            "check (status in ('pending', 'stamping', 'stamped', 'failed', 'rejected', 'cancellation_pending', 'cancelled'))" => 'cfdis_status_valid',
            "check (currency = 'MXN')" => 'cfdis_currency_mxn',
            'check (total > 0)' => 'cfdis_total_positive',
            "check (status not in ('stamped', 'cancellation_pending', 'cancelled') or (uuid is not null and stamped_at is not null and xml_path is not null))" => 'cfdis_stamp_evidence',
            "check (cancellation_motive is null or cancellation_motive in ('01', '02', '03', '04'))" => 'cfdis_cancellation_motive_valid',
            // [V] SAT: el motivo 01 exige relacionar el folio fiscal que sustituye.
            "check (cancellation_motive is distinct from '01' or cancellation_replacement_uuid is not null)" => 'cfdis_motive_01_replacement',
            "check (status not in ('cancellation_pending', 'cancelled') or (cancellation_motive is not null and cancellation_requested_at is not null))" => 'cfdis_cancellation_evidence',
            "check (status <> 'cancelled' or cancelled_at is not null)" => 'cfdis_cancelled_at',
        ] as $check => $name) {
            DB::statement("alter table cfdis add constraint {$name} {$check}");
        }

        DB::statement('create unique index cfdis_uuid_unique on cfdis (uuid) where uuid is not null');
        DB::statement('create unique index cfdis_provider_external_id_unique on cfdis (provider, external_id) where external_id is not null');
        // Un solo CFDI vigente (no cancelado) por donativo.
        DB::statement("create unique index cfdis_one_active_per_donation on cfdis (donation_id) where status <> 'cancelled'");
    }

    public function down(): void
    {
        Schema::dropIfExists('cfdis');
    }
};
