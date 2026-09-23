<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Donativo mensual en un proveedor. Guarda su proveedor para siempre. Cada
 * cobro mensual es un Payment propio (fase-2-diseno-pagos.md §11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('external_id', 100)->nullable();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('MXN');
            $table->string('interval', 20);
            $table->string('status', 20)->index();
            $table->string('provider_status', 50)->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->string('retry_owner', 20);
            $table->timestamp('next_charge_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->foreignId('paused_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('resumed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_source', 20)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->timestamps();

            $table->index('donor_id');
        });

        foreach ([
            "check (provider in ('stripe', 'mercado_pago', 'fake'))" => 'subscriptions_provider_valid',
            "check (interval in ('monthly'))" => 'subscriptions_interval_valid',
            "check (status in ('pending', 'active', 'past_due', 'paused', 'cancelled', 'expired'))" => 'subscriptions_status_valid',
            "check (retry_owner in ('provider', 'crm'))" => 'subscriptions_retry_owner_valid',
            "check (cancellation_source is null or cancellation_source in ('crm_user', 'provider', 'donor'))" => 'subscriptions_cancellation_source_valid',
            'check (amount > 0)' => 'subscriptions_amount_positive',
            "check (currency = 'MXN')" => 'subscriptions_currency_mxn',
            'check (program_id is null or campaign_id is null)' => 'subscriptions_single_destination',
            "check (status <> 'cancelled' or (cancelled_at is not null and cancellation_source is not null))" => 'subscriptions_cancellation_evidence',
            "check (cancellation_source is distinct from 'crm_user' or cancelled_by_id is not null)" => 'subscriptions_crm_cancellation_actor',
        ] as $check => $name) {
            DB::statement("alter table subscriptions add constraint {$name} {$check}");
        }

        DB::statement('create unique index subscriptions_provider_external_id_unique on subscriptions (provider, external_id) where external_id is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
