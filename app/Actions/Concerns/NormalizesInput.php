<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

trait NormalizesInput
{
    /**
     * Recorta textos, convierte cadenas vacías en null (los formularios
     * envían "" cuando un campo opcional queda vacío) y enums en su valor
     * (Filament entrega los Select de enums como objetos).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    protected function normalize(array $input): array
    {
        return array_map(function (mixed $value): mixed {
            if ($value instanceof \BackedEnum) {
                return $value->value;
            }

            if (is_string($value)) {
                $value = trim($value);

                return $value === '' ? null : $value;
            }

            return $value;
        }, $input);
    }
}
