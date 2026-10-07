<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de responsables/procuradores de un donante
 * (docs/tecnico/gestion-relaciones-donantes.md). Reasignar nunca borra ni edita
 * una fila cerrada: cierra la vigente (`ended_at`) y crea una nueva. El índice
 * único parcial es la barrera real contra dos responsables vigentes a la vez;
 * la Action `AssignDonorResponsible` es la primera (bloquea la fila vigente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['donor_id', 'ended_at']);
            $table->index(['user_id', 'ended_at']);
        });

        DB::statement('alter table donor_assignments add constraint donor_assignments_ended_after_started check (ended_at is null or ended_at >= started_at)');

        DB::statement('create unique index donor_assignments_one_active on donor_assignments (donor_id) where ended_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('donor_assignments');
    }
};
