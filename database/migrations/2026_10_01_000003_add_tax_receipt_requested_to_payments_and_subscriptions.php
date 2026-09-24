<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "CFDI solicitado" en donativos en línea: la página pública ya capturaba la
 * petición y los datos fiscales, pero no la guardaba en el pago. Se copia al
 * donativo (y a cada mensualidad) para el aviso a Contabilidad. Los pagos
 * anteriores quedan en `false`: no se infiere nada retroactivamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'subscriptions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->boolean('tax_receipt_requested')->default(false);
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'subscriptions'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('tax_receipt_requested');
            });
        }
    }
};
