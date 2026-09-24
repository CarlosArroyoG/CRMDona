<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Enums\DonorType;
use Illuminate\Validation\Rule;

/**
 * Reglas de nombre, contacto y teléfono del donante. Las comparten el alta en
 * el panel (SaveDonor) y la página pública (ValidatePublicDonationForm), para
 * que un mismo dato nunca se valide distinto según por dónde llegó.
 */
final class DonorIdentityRules
{
    public const string PHONE_MESSAGE = 'El teléfono solo puede tener números, espacios, +, paréntesis y guiones (7 a 30 caracteres).';

    /**
     * @return array<string, list<mixed>>
     */
    public static function for(?DonorType $type): array
    {
        return [
            'first_name' => [Rule::requiredIf($type === DonorType::Individual), 'nullable', 'string', 'max:100'],
            'last_name' => [Rule::requiredIf($type === DonorType::Individual), 'nullable', 'string', 'max:100'],
            'second_last_name' => ['nullable', 'string', 'max:100'],
            'legal_name' => [Rule::requiredIf($type === DonorType::Organization), 'nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9 +()\-]{7,30}$/'],
        ];
    }
}
