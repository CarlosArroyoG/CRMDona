<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA nativo de Filament (#45): aplicación autenticadora (TOTP) con códigos
 * de recuperación. Las columnas son las que documenta Filament. El secreto se
 * guarda cifrado con la llave de la aplicación y los códigos de recuperación
 * como hash dentro de un arreglo cifrado (casts de sus traits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
