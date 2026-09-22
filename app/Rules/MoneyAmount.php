<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MoneyAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! (is_string($value) || is_int($value)) || ! Money::isValid((string) $value)) {
            $fail('El campo :attribute debe ser un importe mayor a cero, con máximo dos decimales y hasta $9,999,999,999.99.');
        }
    }
}
