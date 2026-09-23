<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 (docs/tecnico/fase-3-cfdi.md):
 *
 * - Sustitución de CFDI [V] (esquema de cancelación del SAT 2026): primero se
 *   timbra el CFDI nuevo relacionado con TipoRelacion 04 y después se cancela
 *   el original con motivo 01. Mientras tanto conviven dos CFDI del mismo
 *   donativo: el nuevo lleva `replacement_pending`.
 * - `discarded`: un CFDI rechazado que nunca se timbró puede descartarse.
 * - `payment_attempts.card_funding`: crédito o débito, para la forma de pago
 *   04 / 28 del catálogo del SAT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cfdis', function (Blueprint $table): void {
            $table->foreignId('substitutes_cfdi_id')->nullable()->after('donation_id')->constrained('cfdis')->restrictOnDelete();
            $table->boolean('replacement_pending')->default(false)->after('substitutes_cfdi_id');
            $table->string('substitution_reason', 1000)->nullable()->after('replacement_pending');
        });

        DB::statement('alter table cfdis drop constraint cfdis_status_valid');
        DB::statement('drop index cfdis_one_active_per_donation');

        foreach ([
            "check (status in ('pending', 'stamping', 'stamped', 'failed', 'rejected', 'discarded', 'cancellation_pending', 'cancelled'))" => 'cfdis_status_valid',
            "check (status <> 'discarded' or uuid is null)" => 'cfdis_discarded_never_stamped',
            'check (not replacement_pending or substitutes_cfdi_id is not null)' => 'cfdis_replacement_pending_has_original',
            'check (substitutes_cfdi_id is null or substitution_reason is not null)' => 'cfdis_substitution_reason',
            'check (substitutes_cfdi_id is distinct from id)' => 'cfdis_not_self_substitution',
        ] as $check => $name) {
            DB::statement("alter table cfdis add constraint {$name} {$check}");
        }

        // Un CFDI vigente por donativo, más como máximo una sustitución en curso.
        DB::statement("create unique index cfdis_one_active_per_donation on cfdis (donation_id) where status not in ('cancelled', 'discarded') and not replacement_pending");
        DB::statement("create unique index cfdis_one_replacement_per_donation on cfdis (donation_id) where status not in ('cancelled', 'discarded') and replacement_pending");

        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->string('card_funding', 20)->nullable()->after('card_last4');
        });
        DB::statement("alter table payment_attempts add constraint payment_attempts_card_funding_valid check (card_funding is null or card_funding in ('credit', 'debit', 'prepaid', 'unknown'))");
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table): void {
            $table->dropColumn('card_funding');
        });

        DB::statement('drop index cfdis_one_replacement_per_donation');
        DB::statement('drop index cfdis_one_active_per_donation');
        foreach (['cfdis_status_valid', 'cfdis_discarded_never_stamped', 'cfdis_replacement_pending_has_original', 'cfdis_substitution_reason', 'cfdis_not_self_substitution'] as $name) {
            DB::statement("alter table cfdis drop constraint {$name}");
        }

        Schema::table('cfdis', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('substitutes_cfdi_id');
            $table->dropColumn(['replacement_pending', 'substitution_reason']);
        });

        DB::statement("alter table cfdis add constraint cfdis_status_valid check (status in ('pending', 'stamping', 'stamped', 'failed', 'rejected', 'cancellation_pending', 'cancelled'))");
        DB::statement("create unique index cfdis_one_active_per_donation on cfdis (donation_id) where status <> 'cancelled'");
    }
};
