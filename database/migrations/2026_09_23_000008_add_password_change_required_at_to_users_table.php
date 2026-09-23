<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contraseña temporal: nulo = contraseña definitiva. Con fecha = la
 * contraseña actual es temporal, se generó en ese momento, obliga a
 * cambiarla y vence tras `auth.temporary_password_ttl_hours`. No se guarda
 * ningún secreto: solo el hash habitual en `password`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_change_required_at')->nullable()->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_change_required_at');
        });
    }
};
