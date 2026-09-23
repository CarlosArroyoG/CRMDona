<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un cobro concreto: un pago único o una mensualidad. Sus intentos viven en
 * payment_attempts. Los reembolsos y disputas son tablas aparte: el estado
 * de reembolso se calcula, no se guarda aquí (fase-2-diseno-pagos.md §2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('external_id', 100)->nullable();
            $table->string('kind', 20);
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('billing_period_start')->nullable();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('MXN');
            $table->string('status', 20)->index();
            $table->string('provider_status', 50)->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->string('next_retry_owner', 20)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->timestamps();

            $table->index('donor_id');
            $table->index('created_at');
        });

        foreach ([
            "check (provider in ('stripe', 'mercado_pago', 'fake'))" => 'payments_provider_valid',
            "check (kind in ('one_time', 'recurring_charge'))" => 'payments_kind_valid',
            "check (status in ('pending', 'processing', 'succeeded', 'failed', 'cancelled'))" => 'payments_status_valid',
            "check (next_retry_owner is null or next_retry_owner in ('provider', 'crm'))" => 'payments_next_retry_owner_valid',
            "check ((kind = 'recurring_charge') = (subscription_id is not null))" => 'payments_recurring_has_subscription',
            "check ((kind = 'recurring_charge') = (billing_period_start is not null))" => 'payments_recurring_has_period',
            'check (amount > 0)' => 'payments_amount_positive',
            "check (currency = 'MXN')" => 'payments_currency_mxn',
            'check (program_id is null or campaign_id is null)' => 'payments_single_destination',
            "check (status <> 'succeeded' or succeeded_at is not null)" => 'payments_success_evidence',
        ] as $check => $name) {
            DB::statement("alter table payments add constraint {$name} {$check}");
        }

        DB::statement('create unique index payments_provider_external_id_unique on payments (provider, external_id) where external_id is not null');
        // Una sola mensualidad por periodo de cada suscripción.
        DB::statement('create unique index payments_subscription_period_unique on payments (subscription_id, billing_period_start) where subscription_id is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
