<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incidencias de pagos (RF-01): Nueva → En revisión → Resuelta. `dedupe_key`
 * identifica el hecho concreto: el mismo hecho no se duplica y un hecho
 * distinto sobre el mismo pago abre otra incidencia. Las notas son de solo
 * inserción.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 50)->index();
            $table->string('severity', 20);
            $table->string('failure_category', 40)->nullable();
            $table->string('provider', 20)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained('payment_disputes')->restrictOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20)->index();
            $table->timestamp('detected_at');
            $table->foreignId('reviewing_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewing_started_at')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->string('dedupe_key', 255)->unique();
            $table->timestamps();

            $table->index('payment_id');
            $table->index('subscription_id');
        });

        foreach ([
            "check (type in ('one_time_payment_failed', 'recurring_attempt_failed', 'recurring_payment_failed',
                'subscription_cancelled_by_provider', 'dispute_opened', 'refund_failed', 'succeeded_without_donation',
                'state_inconsistency', 'provider_unavailable', 'webhook_unprocessable'))" => 'payment_incidents_type_valid',
            "check (severity in ('warning', 'critical'))" => 'payment_incidents_severity_valid',
            "check (status in ('new', 'reviewing', 'resolved'))" => 'payment_incidents_status_valid',
            "check (provider is null or provider in ('stripe', 'mercado_pago', 'fake'))" => 'payment_incidents_provider_valid',
            'check (num_nonnulls(payment_id, payment_attempt_id, subscription_id, refund_id, dispute_id, webhook_event_id, provider) >= 1)' => 'payment_incidents_has_reference',
            "check (status <> 'reviewing' or (reviewing_by_id is not null and reviewing_started_at is not null))" => 'payment_incidents_reviewing_evidence',
            "check (status <> 'resolved' or (resolved_by_id is not null and resolved_at is not null and resolution is not null))" => 'payment_incidents_resolution_evidence',
            "check (status = 'resolved' or (resolved_by_id is null and resolved_at is null and resolution is null))" => 'payment_incidents_unresolved_clean',
        ] as $check => $name) {
            DB::statement("alter table payment_incidents add constraint {$name} {$check}");
        }

        Schema::create('payment_incident_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_incident_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index('payment_incident_id');
        });

        DB::statement('alter table payment_incident_notes add constraint payment_incident_notes_body_present check (length(trim(body)) > 0)');

        DB::unprepared(<<<'SQL'
            create or replace function payment_incident_notes_prevent_changes() returns trigger language plpgsql as $$
            begin
                raise exception 'Las notas de incidencias no se modifican ni se eliminan.';
            end;
            $$;
            create trigger payment_incident_notes_append_only before update or delete on payment_incident_notes
                for each row execute function payment_incident_notes_prevent_changes();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_incident_notes');
        DB::unprepared('drop function if exists payment_incident_notes_prevent_changes();');
        Schema::dropIfExists('payment_incidents');
    }
};
