<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de entrada de webhooks. El identificador del evento del proveedor
 * es único: un reenvío no crea otra fila ni otro procesamiento. `payload`
 * solo guarda los campos de la lista permitida (fase-2-diseno-pagos.md §18.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('external_event_id', 255);
            $table->string('event_type', 100);
            $table->string('resource_type', 50)->nullable();
            $table->string('resource_external_id', 100)->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->jsonb('payload');
            $table->timestamp('received_at');
            $table->string('status', 20)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('dispute_id')->nullable()->constrained('payment_disputes')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
            $table->index(['provider', 'resource_type', 'resource_external_id']);
            $table->index('received_at');
        });

        foreach ([
            "check (provider in ('stripe', 'mercado_pago', 'fake'))" => 'webhook_events_provider_valid',
            "check (status in ('pending', 'processed', 'ignored', 'failed'))" => 'webhook_events_status_valid',
            "check (status <> 'processed' or processed_at is not null)" => 'webhook_events_processed_evidence',
        ] as $check => $name) {
            DB::statement("alter table webhook_events add constraint {$name} {$check}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
