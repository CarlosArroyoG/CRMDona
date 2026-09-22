<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de la organización: una sola fila (id = 1). Sin secretos ni
 * configuración de infraestructura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('legal_name')->nullable();
            $table->string('rfc', 13)->nullable();
            $table->string('tax_regime', 3)->nullable();
            $table->string('tax_postal_code', 5)->nullable();
            $table->string('authorization_number')->nullable();
            $table->date('authorization_date')->nullable();
            $table->text('donation_legend')->nullable();
            $table->string('logo_path')->nullable();
            $table->text('email_signature')->nullable();
            $table->string('privacy_notice_url')->nullable();
            $table->string('privacy_notice_version', 50)->nullable();
            $table->timestamps();
        });

        DB::statement('alter table organization_settings add constraint organization_settings_single_row check (id = 1)');
        DB::statement('alter table organization_settings add constraint organization_settings_privacy_notice_pair check ((privacy_notice_url is null) = (privacy_notice_version is null))');
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};
