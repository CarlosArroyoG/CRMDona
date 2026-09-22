<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora (ADR-006). Solo inserción: un trigger impide UPDATE y DELETE.
 * `changed_fields` dice qué cambió; `old_values`/`new_values` guardan valores
 * solo de los campos marcados "con valor" en cada modelo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 30);
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('changed_fields');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['auditable_type', 'auditable_id']);
        });

        DB::unprepared(<<<'SQL'
            create or replace function audit_logs_prevent_changes() returns trigger language plpgsql as $$
            begin
                raise exception 'La bitácora no se modifica ni se elimina.';
            end;
            $$;
            create trigger audit_logs_append_only before update or delete on audit_logs
                for each row execute function audit_logs_prevent_changes();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::unprepared('drop function if exists audit_logs_prevent_changes();');
    }
};
