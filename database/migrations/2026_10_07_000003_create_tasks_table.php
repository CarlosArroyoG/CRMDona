<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tareas (docs/tecnico/gestion-relaciones-donantes.md): pendientes accionables
 * con prioridad y fecha límite. No siempre son de un donante (p. ej. "organizar
 * evento de fin de año"): `donor_id` es opcional. Distinta de `donor_activities`
 * (que registra una interacción por canal, no un pendiente con prioridad).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donor_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assigned_to_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('priority', 20);
            $table->date('due_date')->nullable();
            $table->string('status', 20);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['donor_id', 'due_date']);
            $table->index(['assigned_to_id', 'status', 'due_date']);
        });

        foreach ([
            "check (priority in ('low', 'medium', 'high'))" => 'tasks_priority_valid',
            "check (status in ('pending', 'in_progress', 'done', 'cancelled'))" => 'tasks_status_valid',
            "check ((status = 'done') = (completed_at is not null and completed_by_id is not null))" => 'tasks_done_evidence',
            "check ((status = 'cancelled') = (cancelled_at is not null and cancelled_by_id is not null))" => 'tasks_cancelled_evidence',
        ] as $check => $name) {
            DB::statement("alter table tasks add constraint {$name} {$check}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
