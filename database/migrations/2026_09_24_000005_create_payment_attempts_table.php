<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada intento real de conseguir un cobro. Nunca guarda número de tarjeta,
 * CVV ni vencimiento: solo marca y últimos 4 dígitos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('external_id', 100)->nullable();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 20);
            $table->string('initiated_by', 20);
            $table->string('failure_category', 40)->nullable();
            $table->string('provider_code', 100)->nullable();
            $table->string('provider_message', 500)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'attempt_number']);
        });

        foreach ([
            "check (provider in ('stripe', 'mercado_pago', 'fake'))" => 'payment_attempts_provider_valid',
            "check (status in ('pending', 'succeeded', 'failed'))" => 'payment_attempts_status_valid',
            "check (initiated_by in ('donor', 'provider', 'crm'))" => 'payment_attempts_initiated_by_valid',
            "check (card_last4 is null or card_last4 ~ '^[0-9]{4}$')" => 'payment_attempts_card_last4_digits',
            'check (attempt_number > 0)' => 'payment_attempts_number_positive',
            "check (status <> 'failed' or failure_category is not null)" => 'payment_attempts_failure_categorized',
        ] as $check => $name) {
            DB::statement("alter table payment_attempts add constraint {$name} {$check}");
        }

        DB::statement('create unique index payment_attempts_provider_external_id_unique on payment_attempts (provider, external_id) where external_id is not null');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
