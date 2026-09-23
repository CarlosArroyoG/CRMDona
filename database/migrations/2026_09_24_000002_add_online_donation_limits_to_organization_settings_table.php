<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Límites de negocio de los donativos en línea. Nulo = sin límite adicional
 * de la organización; siempre aplican además los límites técnicos del
 * proveedor (docs/tecnico/fase-2-diseno-pagos.md §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->decimal('online_donation_min_amount', 12, 2)->nullable();
            $table->decimal('online_donation_max_amount', 12, 2)->nullable();
        });

        DB::statement('alter table organization_settings add constraint organization_settings_online_limits_positive
            check ((online_donation_min_amount is null or online_donation_min_amount > 0)
               and (online_donation_max_amount is null or online_donation_max_amount > 0))');
        DB::statement('alter table organization_settings add constraint organization_settings_online_limits_order
            check (online_donation_min_amount is null or online_donation_max_amount is null
               or online_donation_min_amount <= online_donation_max_amount)');
    }

    public function down(): void
    {
        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->dropColumn(['online_donation_min_amount', 'online_donation_max_amount']);
        });
    }
};
