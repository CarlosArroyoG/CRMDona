<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Donativo reconocido por el CRM (ADR-005). No es un pago, ni un recibo, ni
 * un CFDI: esos tendrán tablas propias que apunten a `donations`.
 * Nunca se elimina (trigger); los errores se corrigen cancelando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            // Un solo destino: campaña, programa o ninguno (fondo general).
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('payment_method', 20)->nullable();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('MXN');
            $table->date('received_on')->index();
            $table->string('status', 20)->index();
            $table->string('reference', 100)->nullable();
            $table->text('in_kind_description')->nullable();
            $table->boolean('tax_receipt_requested')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('registered_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });

        foreach ([
            "check (kind in ('monetary', 'in_kind'))" => 'donations_kind_valid',
            "check (status in ('pending', 'confirmed', 'cancelled'))" => 'donations_status_valid',
            "check (payment_method is null or payment_method in ('cash', 'bank_transfer', 'check', 'bank_deposit'))" => 'donations_payment_method_valid',
            'check (amount > 0)' => 'donations_amount_positive',
            "check (currency = 'MXN')" => 'donations_currency_mxn',
            'check (program_id is null or campaign_id is null)' => 'donations_single_destination',
            "check ((kind = 'monetary' and payment_method is not null and in_kind_description is null)
                or (kind = 'in_kind' and payment_method is null and in_kind_description is not null))" => 'donations_fields_by_kind',
            // Pendiente: sin evidencia. Confirmado: con evidencia de confirmación.
            // Cancelado: con evidencia de cancelación (y de confirmación si la tuvo).
            "check (status <> 'pending' or (confirmed_at is null and confirmed_by_id is null and cancelled_at is null and cancelled_by_id is null and cancellation_reason is null))" => 'donations_pending_clean',
            "check (status <> 'confirmed' or (confirmed_at is not null and confirmed_by_id is not null and cancelled_at is null))" => 'donations_confirmation_evidence',
            "check (status <> 'cancelled' or (cancelled_at is not null and cancelled_by_id is not null and cancellation_reason is not null))" => 'donations_cancellation_evidence',
            'check ((confirmed_at is null) = (confirmed_by_id is null))' => 'donations_confirmation_pair',
        ] as $check => $name) {
            DB::statement("alter table donations add constraint {$name} {$check}");
        }

        DB::unprepared(<<<'SQL'
            create or replace function donations_prevent_delete() returns trigger language plpgsql as $$
            begin
                raise exception 'Los donativos no se eliminan; se cancelan con un motivo.';
            end;
            $$;
            create trigger donations_no_delete before delete on donations
                for each row execute function donations_prevent_delete();

            create or replace function campaigns_lock_program() returns trigger language plpgsql as $$
            begin
                if new.program_id is distinct from old.program_id
                    and exists (select 1 from donations where campaign_id = old.id) then
                    raise exception 'La campaña ya tiene donativos: no puede cambiar de programa.';
                end if;
                return new;
            end;
            $$;
            create trigger campaigns_program_locked before update of program_id on campaigns
                for each row execute function campaigns_lock_program();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists campaigns_program_locked on campaigns;
            drop function if exists campaigns_lock_program();
        SQL);
        Schema::dropIfExists('donations');
        DB::unprepared('drop function if exists donations_prevent_delete();');
    }
};
