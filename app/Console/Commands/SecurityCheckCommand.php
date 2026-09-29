<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SensitiveColumns;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Revisa la configuración de seguridad del entorno donde corre
 * (docs/tecnico/proteccion-de-datos.md). Se ejecuta después de cada
 * despliegue: `php artisan app:security-check`. No muestra secretos: solo
 * dice qué está bien y qué hay que corregir. Termina con error si algo falla.
 */
#[Signature('app:security-check')]
#[Description('Revisa usuario de base de datos, cifrado, sesión, Redis y modo depuración')]
class SecurityCheckCommand extends Command
{
    public function handle(): int
    {
        $checks = [
            ['APP_KEY configurada', config()->string('app.key') !== ''],
            ['APP_DEBUG apagado', ! config()->boolean('app.debug')],
            ['Cookie de sesión solo por HTTPS', (bool) config('session.secure')],
            ['Sesión cifrada (SESSION_ENCRYPT)', (bool) config('session.encrypt')],
            ['Redis con contraseña', filled(config('database.redis.default.password'))],
            ...$this->databaseChecks(),
        ];

        $failed = 0;
        foreach ($checks as [$label, $ok]) {
            $ok ? $this->components->twoColumnDetail($label, '<fg=green>OK</>') : $this->components->twoColumnDetail($label, '<fg=red>CORREGIR</>');
            $failed += $ok ? 0 : 1;
        }

        if ($failed > 0) {
            $this->components->error("{$failed} punto(s) por corregir. Ver docs/tecnico/proteccion-de-datos.md.");

            return self::FAILURE;
        }

        $this->components->info('Configuración de seguridad correcta.');

        return self::SUCCESS;
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    private function databaseChecks(): array
    {
        try {
            $role = DB::selectOne(<<<'SQL'
                select r.rolsuper as superuser,
                       r.rolcreaterole or r.rolcreatedb as can_create_roles_or_databases,
                       has_schema_privilege(current_user, 'public', 'CREATE') as can_create_tables,
                       pg_get_userbyid(c.relowner) = current_user as owns_donors
                from pg_roles r
                left join pg_class c on c.relname = 'donors' and c.relnamespace = 'public'::regnamespace
                where r.rolname = current_user
                SQL);
        } catch (Throwable) {
            return [['Conexión a la base de datos', false]];
        }

        return [
            ['Datos sensibles de donantes cifrados (si no: app:encrypt-legacy-data)', SensitiveColumns::pendingCount() === 0],
            ['Usuario de la base sin superusuario', ! $role->superuser],
            ['Usuario de la base sin crear usuarios ni bases', ! $role->can_create_roles_or_databases],
            ['Usuario de la base sin crear tablas (mínimo privilegio)', ! $role->can_create_tables],
            ['Usuario de la base no es dueño de las tablas', ! $role->owns_donors],
        ];
    }
}
