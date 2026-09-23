<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 7 — índices respaldados por consultas reales (docs/tecnico/rendimiento.md):
 *
 * - donors (lower(email)): la página pública (ResolvePublicDonor) y
 *   FindDonorDuplicates buscan con `lower(email) = ?`; el índice sobre
 *   `email` no se usa con la función.
 * - donations (donor_id, campaign_id, program_id): llaves foráneas sin índice
 *   (PostgreSQL no las crea). Las usan la ficha del donante, los filtros de
 *   campaña/programa, las métricas del tablero y las verificaciones de las FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists donors_lower_email_index on donors (lower(email))');
        DB::statement('create index if not exists donations_donor_id_index on donations (donor_id)');
        DB::statement('create index if not exists donations_campaign_id_index on donations (campaign_id) where campaign_id is not null');
        DB::statement('create index if not exists donations_program_id_index on donations (program_id) where program_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists donations_program_id_index');
        DB::statement('drop index if exists donations_campaign_id_index');
        DB::statement('drop index if exists donations_donor_id_index');
        DB::statement('drop index if exists donors_lower_email_index');
    }
};
