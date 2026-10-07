<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Actividades de relación con donantes (docs/tecnico/gestion-relaciones-donantes.md):
 * interacciones humanas registradas a mano (llamada, visita, WhatsApp informal…).
 * No sustituye `communications`, que sigue siendo el correo/WhatsApp que envía el
 * sistema. Siempre ligada a un donante: es, por definición, una interacción de
 * procuración con alguien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('subject', 255);
            $table->text('description')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->text('next_action')->nullable();
            $table->string('status', 20);
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['donor_id', 'scheduled_at']);
            $table->index(['assigned_to_id', 'status']);
        });

        foreach ([
            "check (type in ('call', 'whatsapp', 'email', 'meeting', 'visit', 'follow_up', 'note', 'proposal', 'thanks', 'other'))" => 'donor_activities_type_valid',
            "check (status in ('scheduled', 'completed', 'cancelled'))" => 'donor_activities_status_valid',
            "check ((status = 'completed') = (completed_at is not null and completed_by_id is not null))" => 'donor_activities_completed_evidence',
            "check ((status = 'cancelled') = (cancelled_at is not null and cancelled_by_id is not null))" => 'donor_activities_cancelled_evidence',
        ] as $check => $name) {
            DB::statement("alter table donor_activities add constraint {$name} {$check}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_activities');
    }
};
