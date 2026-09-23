<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Donativos en línea (fase-2-diseno-pagos.md §14). Los existentes quedan
 * `origin = manual` y conservan su forma de pago en `manual_payment_method`.
 *
 * - manual: actor humano que registra, sin pago, forma de pago manual si es dinero.
 * - online: nace de un Payment exitoso (payment_id único), sin actor humano,
 *   sin forma de pago manual, solo dinero y siempre confirmado.
 *
 * No existe usuario "sistema": el origen se rastrea con payment_id.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const array PHASE_1_CHECKS = [
        'donations_payment_method_valid' => "check (payment_method is null or payment_method in ('cash', 'bank_transfer', 'check', 'bank_deposit'))",
        'donations_fields_by_kind' => "check ((kind = 'monetary' and payment_method is not null and in_kind_description is null)
            or (kind = 'in_kind' and payment_method is null and in_kind_description is not null))",
        'donations_confirmation_evidence' => "check (status <> 'confirmed' or (confirmed_at is not null and confirmed_by_id is not null and cancelled_at is null))",
        'donations_confirmation_pair' => 'check ((confirmed_at is null) = (confirmed_by_id is null))',
    ];

    public function up(): void
    {
        foreach (array_keys(self::PHASE_1_CHECKS) as $name) {
            DB::statement("alter table donations drop constraint {$name}");
        }

        DB::statement('alter table donations rename column payment_method to manual_payment_method');
        DB::statement("alter table donations add column origin varchar(20) not null default 'manual'");
        DB::statement('alter table donations add column payment_id bigint null references payments (id) on delete restrict');
        DB::statement('alter table donations alter column registered_by_id drop not null');
        // Como máximo un donativo por pago.
        DB::statement('create unique index donations_payment_id_unique on donations (payment_id) where payment_id is not null');
        DB::statement('create index donations_origin_index on donations (origin)');

        foreach ([
            'donations_origin_valid' => "check (origin in ('manual', 'online'))",
            'donations_manual_payment_method_valid' => "check (manual_payment_method is null or manual_payment_method in ('cash', 'bank_transfer', 'check', 'bank_deposit'))",
            'donations_fields_by_kind' => "check (
                (kind = 'monetary' and in_kind_description is null
                    and ((origin = 'manual' and manual_payment_method is not null) or (origin = 'online' and manual_payment_method is null)))
                or (kind = 'in_kind' and manual_payment_method is null and in_kind_description is not null))",
            'donations_origin_manual' => "check (origin <> 'manual' or (registered_by_id is not null and payment_id is null))",
            'donations_origin_online' => "check (origin <> 'online' or (payment_id is not null and registered_by_id is null
                and manual_payment_method is null and kind = 'monetary' and status <> 'pending' and confirmed_by_id is null))",
            'donations_confirmation_evidence' => "check (status <> 'confirmed' or (confirmed_at is not null and cancelled_at is null
                and (confirmed_by_id is not null or origin = 'online')))",
            'donations_confirmation_pair' => "check (origin <> 'manual' or (confirmed_at is null) = (confirmed_by_id is null))",
        ] as $name => $check) {
            DB::statement("alter table donations add constraint {$name} {$check}");
        }
    }

    /**
     * Solo se puede revertir sin donativos en línea: la Fase 1 exige un
     * usuario que registre y no tiene dónde guardar el pago. Los donativos
     * nunca se eliminan, así que la reversión se detiene en lugar de perder datos.
     */
    public function down(): void
    {
        if (DB::table('donations')->where('origin', 'online')->exists()) {
            throw new RuntimeException('Hay donativos en línea: revertir esta migración perdería su origen. Reversión detenida.');
        }

        foreach ([
            'donations_origin_valid', 'donations_manual_payment_method_valid', 'donations_fields_by_kind',
            'donations_origin_manual', 'donations_origin_online', 'donations_confirmation_evidence',
            'donations_confirmation_pair',
        ] as $name) {
            DB::statement("alter table donations drop constraint {$name}");
        }

        DB::statement('drop index donations_origin_index');
        DB::statement('drop index donations_payment_id_unique');
        DB::statement('alter table donations alter column registered_by_id set not null');
        DB::statement('alter table donations drop column payment_id');
        DB::statement('alter table donations drop column origin');
        DB::statement('alter table donations rename column manual_payment_method to payment_method');

        foreach (self::PHASE_1_CHECKS as $name => $check) {
            DB::statement("alter table donations add constraint {$name} {$check}");
        }
    }
};
