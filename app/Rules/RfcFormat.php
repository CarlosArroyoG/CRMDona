<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\DonorType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Solo valida la estructura del RFC (12 caracteres persona moral, 13 persona
 * física). Reglas fiscales adicionales (RFC genérico, compatibilidad con el
 * régimen) están PENDIENTES de confirmar con el contador/PAC.
 */
class RfcFormat implements ValidationRule
{
    public function __construct(private readonly DonorType $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pattern = match ($this->type) {
            DonorType::Individual => '/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/u',
            DonorType::Organization => '/^[A-ZÑ&]{3}\d{6}[A-Z0-9]{3}$/u',
        };

        if (! is_string($value) || preg_match($pattern, mb_strtoupper($value)) !== 1) {
            $fail("El campo :attribute no tiene la estructura de un RFC de {$this->type->getLabel()} ({$this->type->rfcLength()} caracteres).");
        }
    }
}
