<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disputas y contracargos. Separados de PaymentStatus: no alteran el pago,
 * el donativo, el recibo ni el CFDI (fase-2-diseno-pagos.md §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('external_id', 100);
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('MXN');
            $table->string('status', 20)->index();
            $table->string('provider_status', 50)->nullable();
            $table->string('provider_reason', 100)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('evidence_due_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->index('payment_id');
        });

        foreach ([
            "check (provider in ('stripe', 'mercado_pago', 'fake'))" => 'payment_disputes_provider_valid',
            "check (status in ('open', 'under_review', 'won', 'lost', 'closed'))" => 'payment_disputes_status_valid',
            'check (amount is null or amount > 0)' => 'payment_disputes_amount_positive',
        ] as $check => $name) {
            DB::statement("alter table payment_disputes add constraint {$name} {$check}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_disputes');
    }
};
