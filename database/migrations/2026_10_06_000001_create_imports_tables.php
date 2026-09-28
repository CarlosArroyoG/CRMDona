<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carga masiva de donantes por CSV (docs/tecnico/carga-y-envios-masivos.md):
 *
 * - `imports` y `failed_import_rows`: tablas del importador de Filament. Las
 *   filas rechazadas contienen datos personales: se purgan con la importación
 *   a los 7 días (`model:prune`, igual que las exportaciones, ADR-007).
 * - `donors.origin` admite `csv_import`: el donante lo registró una persona
 *   del equipo con un archivo, así que también exige `registered_by_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('completed_at')->nullable();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('importer');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('successful_rows')->default(0);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('failed_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->json('data');
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->text('validation_error')->nullable();
            $table->timestamps();
        });

        DB::statement('alter table donors drop constraint donors_origin_valid');
        DB::statement('alter table donors drop constraint donors_origin_actor');
        DB::statement("alter table donors add constraint donors_origin_valid check (origin in ('manual', 'public_page', 'csv_import'))");
        DB::statement("alter table donors add constraint donors_origin_actor check ((origin in ('manual', 'csv_import')) = (registered_by_id is not null))");
    }

    public function down(): void
    {
        if (DB::table('donors')->where('origin', 'csv_import')->exists()) {
            throw new RuntimeException('No se revierte: ya hay donantes cargados por CSV.');
        }

        DB::statement('alter table donors drop constraint donors_origin_actor');
        DB::statement('alter table donors drop constraint donors_origin_valid');
        DB::statement("alter table donors add constraint donors_origin_valid check (origin in ('manual', 'public_page'))");
        DB::statement("alter table donors add constraint donors_origin_actor check ((origin = 'manual') = (registered_by_id is not null))");

        Schema::dropIfExists('failed_import_rows');
        Schema::dropIfExists('imports');
    }
};
