<?php

declare(strict_types=1);

namespace App\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Enum respaldado por texto que se guarda cifrado con APP_KEY (catálogos
 * fiscales como el régimen o el uso de CFDI). Si el valor no cambia, conserva
 * el texto cifrado anterior: así un guardado sin cambios no parece una
 * modificación en la bitácora.
 *
 * Uso: `'tax_regime' => EncryptedEnum::class.':'.TaxRegime::class`.
 *
 * @implements CastsAttributes<BackedEnum, BackedEnum|string>
 */
final class EncryptedEnum implements CastsAttributes
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(private readonly string $enum)
    {
        if (! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidArgumentException("{$enum} no es un enum respaldado.");
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackedEnum
    {
        if ($value === null) {
            return null;
        }

        return ($this->enum)::from(Crypt::decryptString((string) $value));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $plain = (string) ($value instanceof BackedEnum ? $value->value : ($this->enum)::from($value)->value);

        $current = $attributes[$key] ?? null;
        if (is_string($current) && Crypt::decryptString($current) === $plain) {
            return $current;
        }

        return Crypt::encryptString($plain);
    }
}
