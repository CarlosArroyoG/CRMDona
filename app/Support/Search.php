<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Búsqueda sin acentos ni mayúsculas con `f_unaccent` (ADR-009):
 * "jose pena" encuentra "José Peña". Solo columnas de una lista cerrada, para
 * que el SQL nunca se construya con texto variable.
 */
final class Search
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function unaccent(Builder $query, string $column, string $term, string $boolean = 'and'): Builder
    {
        $sql = match ($column) {
            'name' => 'f_unaccent(name) ilike f_unaccent(?)',
            'display_name' => 'f_unaccent(display_name) ilike f_unaccent(?)',
            'email' => 'f_unaccent(email) ilike f_unaccent(?)',
            default => throw new InvalidArgumentException("Columna de búsqueda no permitida: {$column}"),
        };

        return $query->whereRaw($sql, ['%'.addcslashes(trim($term), '%_\\').'%'], $boolean);
    }
}
