<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reembolsos: Payment 1 → N Refund. Nunca borran ni cancelan el pago ni el
 * donativo (fase-2-diseno-pagos.md §9).
 *
 * Reservan saldo los reembolsos `pending` (pueden completarse en el
 * proveedor en cualquier momento) y `succeeded`. `failed` y `cancelled`
 * liberan su importe. El trigger es la segunda barrera: la primera es la
 * Action RequestRefund, que valida con el Payment bloqueado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('external_id', 100)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20);
            $table->string('reason', 30);
            $table->text('reason_comment')->nullable();
            $table->string('provider_reason', 50)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('source', 20);
            $table->foreignId('requested_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
        });

        foreach ([
            "check (provider in ('stripe', 'mercado_pago', 'fake'))" => 'refunds_provider_valid',
            "check (status in ('pending', 'succeeded', 'failed', 'cancelled'))" => 'refunds_status_valid',
            "check (reason in ('donor_request', 'duplicate_charge', 'incorrect_amount', 'administrative_error', 'other', 'provider_initiated'))" => 'refunds_reason_valid',
            "check (source in ('crm', 'provider'))" => 'refunds_source_valid',
            'check (amount > 0)' => 'refunds_amount_positive',
            "check (reason <> 'other' or (reason_comment is not null and length(trim(reason_comment)) > 0))" => 'refunds_other_requires_comment',
            // Lo pide una persona desde el CRM, o llega del proveedor (por ejemplo, desde su panel).
            "check ((source = 'crm') = (requested_by_id is not null))" => 'refunds_crm_requires_actor',
            "check ((source = 'provider') = (reason = 'provider_initiated'))" => 'refunds_provider_reason',
        ] as $check => $name) {
            DB::statement("alter table refunds add constraint {$name} {$check}");
        }

        DB::statement('create unique index refunds_provider_external_id_unique on refunds (provider, external_id) where external_id is not null');

        DB::unprepared(<<<'SQL'
            create or replace function refunds_within_payment_amount() returns trigger language plpgsql as $$
            declare
                payment_amount numeric(12, 2);
                reserved numeric(12, 2);
            begin
                if new.status not in ('pending', 'succeeded') then
                    return new;
                end if;

                -- Serializa las solicitudes del mismo pago: la suma siguiente
                -- ya ve lo confirmado por la transacción que tenía el bloqueo.
                select amount into payment_amount from payments where id = new.payment_id for update;

                select coalesce(sum(amount), 0) into reserved from refunds
                    where payment_id = new.payment_id
                      and status in ('pending', 'succeeded')
                      and id is distinct from new.id;

                if reserved + new.amount > payment_amount then
                    raise exception 'La suma de reembolsos no puede superar el importe del pago.'
                        using errcode = 'check_violation';
                end if;

                return new;
            end;
            $$;
            create trigger refunds_within_payment_amount before insert or update of amount, status, payment_id on refunds
                for each row execute function refunds_within_payment_amount();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        DB::unprepared('drop function if exists refunds_within_payment_amount();');
    }
};
