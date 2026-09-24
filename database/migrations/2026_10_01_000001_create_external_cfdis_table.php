<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cambio de alcance (docs/tecnico/cfdi-externo.md): el CRM ya no emite,
 * timbra, cancela ni sustituye CFDI. La contadora factura fuera del CRM y
 * aquí solo se conservan los CFDI externos como antecedentes documentales de
 * cada donativo.
 *
 * - `external_cfdis`: XML (y PDF opcional) cargado por una persona, con los
 *   datos que se leen del propio XML (UUID, fechas, total).
 * - Los CFDI que el CRM llegó a timbrar (`cfdis`, `global_cfdis`) se copian
 *   como antecedentes `crm_legacy` apuntando a sus mismos archivos. Las
 *   tablas originales NO se borran: quedan como historial de solo lectura.
 * - Se retira la exigencia de códigos SAT en donativos en especie (era un
 *   requisito de la emisión); las columnas y sus valores se conservan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_cfdis', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donation_id')->constrained()->restrictOnDelete();
            $table->uuid('uuid');
            $table->timestamp('issued_at');
            $table->timestamp('stamped_at')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->string('xml_path', 255);
            $table->string('pdf_path', 255)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('source', 20);
            $table->foreignId('legacy_cfdi_id')->nullable()->constrained('cfdis')->restrictOnDelete();
            $table->foreignId('legacy_global_cfdi_id')->nullable()->constrained('global_cfdis')->restrictOnDelete();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('removal_reason', 1000)->nullable();
            $table->timestamps();

            $table->index('donation_id');
            $table->index('uuid');
        });

        foreach ([
            "check (source in ('upload', 'crm_legacy'))" => 'external_cfdis_source_valid',
            "check ((source = 'upload') = (uploaded_by_id is not null))" => 'external_cfdis_upload_actor',
            "check (source <> 'crm_legacy' or num_nonnulls(legacy_cfdi_id, legacy_global_cfdi_id) = 1)" => 'external_cfdis_legacy_reference',
            'check (removed_at is null or removal_reason is not null)' => 'external_cfdis_removal_reason',
            'check (removed_by_id is null or removed_at is not null)' => 'external_cfdis_removal_actor',
        ] as $check => $name) {
            DB::statement("alter table external_cfdis add constraint {$name} {$check}");
        }

        // Un mismo UUID vigente una sola vez por donativo (una factura global externa puede adjuntarse a varios).
        DB::statement('create unique index external_cfdis_active_uuid_per_donation on external_cfdis (donation_id, uuid) where removed_at is null');

        $now = now();

        // CFDI individuales timbrados por el CRM antes del cambio de alcance.
        DB::statement("insert into external_cfdis (donation_id, uuid, issued_at, stamped_at, total, xml_path, pdf_path, notes, source, legacy_cfdi_id,
                uploaded_at, removed_at, removed_by_id, removal_reason, created_at, updated_at)
            select donation_id, uuid, stamped_at, stamped_at, total, xml_path, pdf_path,
                'Timbrado por el CRM antes del cambio de alcance (CFDI #' || id || ').', 'crm_legacy', id,
                stamped_at,
                case when status = 'cancelled' then cancelled_at end,
                case when status = 'cancelled' then cancellation_requested_by_id end,
                case when status = 'cancelled' then 'Cancelado desde el CRM antes del cambio de alcance (motivo ' || coalesce(cancellation_motive, '?') || ').' end,
                ?, ?
            from cfdis
            where donation_id is not null and uuid is not null and xml_path is not null and stamped_at is not null", [$now, $now]);

        // Facturas globales timbradas por el CRM: un antecedente por cada donativo que incluían.
        DB::statement("insert into external_cfdis (donation_id, uuid, issued_at, stamped_at, total, xml_path, pdf_path, notes, source, legacy_global_cfdi_id,
                uploaded_at, created_at, updated_at)
            select dg.donation_id, g.uuid, g.stamped_at, g.stamped_at, g.total, g.xml_path, g.pdf_path,
                'Factura global timbrada por el CRM antes del cambio de alcance (global #' || g.id || ', operación ' || dg.operation_number || ').',
                'crm_legacy', g.id, g.stamped_at, ?, ?
            from global_cfdis g join donation_global_cfdi dg on dg.global_cfdi_id = g.id
            where g.uuid is not null and g.xml_path is not null and g.stamped_at is not null", [$now, $now]);

        DB::statement('alter table donations drop constraint if exists donations_in_kind_evidence');
    }

    public function down(): void
    {
        if (DB::table('external_cfdis')->where('source', 'upload')->exists()) {
            throw new RuntimeException('No se revierte: hay CFDI externos cargados por usuarios (se perderían sus registros).');
        }

        Schema::dropIfExists('external_cfdis');

        DB::statement("alter table donations add constraint donations_in_kind_evidence check (kind <> 'in_kind' or (in_kind_quantity > 0 and in_kind_unit_code is not null and in_kind_product_service_code is not null and in_kind_unit_value >= 0 and in_kind_total_value > 0)) not valid");
    }
};
