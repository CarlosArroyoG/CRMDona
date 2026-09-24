<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cobro asistido (docs/tecnico/solicitudes-de-pago.md):
 *
 * - `payment_requests`: el personal prepara un donativo con tarjeta (donante,
 *   importe, frecuencia, destino y CFDI solicitado) y el donante lo paga en
 *   /donar con un enlace. El token se guarda como hash (búsqueda) y cifrado
 *   (volver a copiarlo o enviarlo); nunca en texto plano. No es un donativo:
 *   el Donation lo crea solo el pago exitoso que confirma el proveedor.
 * - `communications.payment_request_id` y el tipo `payment_request`: correo
 *   individual y transaccional con el enlace de una solicitud concreta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->unsignedInteger('token_version')->default(1);
            $table->unsignedInteger('attempt')->default(0);
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('frequency', 20);
            $table->foreignId('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('tax_receipt_requested')->default(false);
            $table->string('status', 20);
            $table->timestamp('expires_at');
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('donor_id');
        });

        foreach ([
            'check (amount > 0)' => 'payment_requests_amount_positive',
            "check (frequency in ('one_time', 'monthly'))" => 'payment_requests_frequency_valid',
            "check (status in ('open', 'paid', 'cancelled'))" => 'payment_requests_status_valid',
            'check (campaign_id is null or program_id is null)' => 'payment_requests_single_destination',
            "check ((status = 'paid') = (paid_at is not null))" => 'payment_requests_paid_at',
            "check (status <> 'cancelled' or cancelled_at is not null)" => 'payment_requests_cancelled_at',
            "check (frequency = 'monthly' or subscription_id is null)" => 'payment_requests_subscription_monthly',
        ] as $check => $name) {
            DB::statement("alter table payment_requests add constraint {$name} {$check}");
        }

        Schema::table('communications', function (Blueprint $table): void {
            $table->foreignId('payment_request_id')->nullable()->constrained()->restrictOnDelete();
        });

        DB::statement('alter table communications drop constraint communications_kind_valid');
        DB::statement("alter table communications add constraint communications_kind_valid check (kind in ('thank_you', 'cfdi', 'birthday', 'payment_request'))");
        DB::statement("alter table communications add constraint communications_payment_request_reference check (kind <> 'payment_request' or payment_request_id is not null)");
        DB::statement('alter table message_templates drop constraint message_templates_kind_valid');
        DB::statement("alter table message_templates add constraint message_templates_kind_valid check (kind in ('thank_you', 'cfdi', 'birthday', 'payment_request'))");
    }

    public function down(): void
    {
        // El historial de envíos es evidencia: no se revierte si ya hay correos de solicitudes.
        if (DB::table('communications')->where('kind', 'payment_request')->exists()) {
            throw new RuntimeException('No se revierte: el historial ya tiene correos de solicitudes de pago.');
        }

        DB::table('message_templates')->where('kind', 'payment_request')->delete();
        DB::statement('alter table message_templates drop constraint message_templates_kind_valid');
        DB::statement("alter table message_templates add constraint message_templates_kind_valid check (kind in ('thank_you', 'cfdi', 'birthday'))");
        DB::statement('alter table communications drop constraint communications_payment_request_reference');
        DB::statement('alter table communications drop constraint communications_kind_valid');
        DB::statement("alter table communications add constraint communications_kind_valid check (kind in ('thank_you', 'cfdi', 'birthday'))");

        Schema::table('communications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_request_id');
        });

        Schema::dropIfExists('payment_requests');
    }
};
