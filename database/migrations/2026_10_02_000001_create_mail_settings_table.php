<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correo saliente administrable (docs/tecnico/correo-saliente.md): una sola
 * fila (id = 1) con la configuración SMTP estándar. La contraseña se guarda
 * cifrada con la llave de la aplicación (cast `encrypted` de Laravel); nunca
 * en texto plano. Mientras `enabled` sea falso, el CRM usa MAIL_* del entorno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('host', 255)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('encryption', 10)->default('starttls');
            $table->string('username', 255)->nullable();
            $table->text('password')->nullable();
            $table->string('from_address', 255)->nullable();
            $table->string('from_name', 255)->nullable();
            $table->string('reply_to_address', 255)->nullable();
            $table->string('reply_to_name', 255)->nullable();
            $table->unsignedSmallInteger('timeout')->default(30);
            $table->timestamp('last_successful_test_at')->nullable();
            $table->foreignId('last_successful_test_by_id')->nullable()->constrained('users')->nullOnDelete();
            // Sube en cada guardado: los workers comparan este número para tomar la configuración nueva.
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        foreach ([
            'check (id = 1)' => 'mail_settings_single_row',
            "check (encryption in ('starttls', 'tls', 'none'))" => 'mail_settings_encryption_valid',
            'check (port is null or port between 1 and 65535)' => 'mail_settings_port_valid',
            'check (timeout between 1 and 120)' => 'mail_settings_timeout_valid',
            'check (not enabled or (host is not null and port is not null and from_address is not null))' => 'mail_settings_enabled_complete',
        ] as $check => $name) {
            DB::statement("alter table mail_settings add constraint {$name} {$check}");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
