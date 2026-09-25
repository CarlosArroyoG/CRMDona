<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aviso de privacidad publicado por el CRM (`/aviso-de-privacidad`): el
 * domicilio del responsable y el correo para asuntos de privacidad los
 * captura Administración; el CRM no los inventa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->string('privacy_address', 500)->nullable();
            $table->string('privacy_contact_email', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organization_settings', function (Blueprint $table): void {
            $table->dropColumn(['privacy_address', 'privacy_contact_email']);
        });
    }
};
