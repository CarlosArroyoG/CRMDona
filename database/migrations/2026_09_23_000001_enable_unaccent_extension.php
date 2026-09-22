<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda sin acentos (ADR-009). `unaccent()` no es IMMUTABLE, por eso se
 * envuelve en `f_unaccent()`, que sí lo es y podrá indexarse si hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        $available = DB::selectOne("select 1 as ok from pg_available_extensions where name = 'unaccent'");

        if ($available === null) {
            throw new RuntimeException(
                'PostgreSQL no tiene disponible la extensión "unaccent", necesaria para la búsqueda sin acentos. '
                .'Usa una imagen oficial de PostgreSQL (incluye las extensiones contrib). Ver docs/tecnico/despliegue-coolify.md.'
            );
        }

        DB::statement('create extension if not exists unaccent');
        DB::statement(<<<'SQL'
            create or replace function f_unaccent(text) returns text
            language sql immutable parallel safe strict
            as $$ select public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);
    }

    public function down(): void
    {
        DB::statement('drop function if exists f_unaccent(text)');
        DB::statement('drop extension if exists unaccent');
    }
};
