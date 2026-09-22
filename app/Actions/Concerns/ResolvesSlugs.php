<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Identificador para los enlaces públicos futuros (sin acentos, minúsculas y
 * guiones). Si se deja vacío, se genera a partir del nombre.
 */
trait ResolvesSlugs
{
    /**
     * @param  class-string<Model>  $model
     * @return list<mixed>
     */
    protected function slugRules(string $model, ?int $ignoreId): array
    {
        return [
            'nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::unique($model, 'slug')->ignore($ignoreId),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function resolveSlug(string $model, ?string $slug, string $name, ?int $ignoreId): string
    {
        if ($slug !== null) {
            return $slug;
        }

        $base = Str::slug($name) ?: 'sin-nombre';
        $candidate = $base;
        $suffix = 2;

        while ($model::query()->where('slug', $candidate)->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
