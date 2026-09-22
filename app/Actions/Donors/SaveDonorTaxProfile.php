<?php

declare(strict_types=1);

namespace App\Actions\Donors;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\CfdiUse;
use App\Enums\TaxRegime;
use App\Models\Donor;
use App\Models\DonorTaxProfile;
use App\Rules\RfcFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Datos fiscales del donante (solo si pidió comprobante deducible). Solo se
 * valida la estructura; las reglas fiscales están PENDIENTES de confirmar
 * con el contador/PAC. `null` elimina los datos fiscales.
 */
class SaveDonorTaxProfile
{
    use NormalizesInput;

    /**
     * @param  array<string, mixed>|null  $input
     *
     * @throws ValidationException
     */
    public function handle(Donor $donor, ?array $input): ?DonorTaxProfile
    {
        $data = $input === null ? null : $this->validate($donor, $input);

        return DB::transaction(function () use ($donor, $data): ?DonorTaxProfile {
            $profile = $donor->taxProfile()->first();

            if ($data === null) {
                $profile?->delete();

                return null;
            }

            if ($profile === null) {
                return $donor->taxProfile()->create($data);
            }

            $profile->fill($data)->save();

            return $profile;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Donor $donor, array $input, string $prefix = ''): array
    {
        $input = $this->normalize($input);
        if (is_string($input['rfc'] ?? null)) {
            $input['rfc'] = mb_strtoupper($input['rfc']);
        }

        try {
            $data = Validator::make($input, [
                'rfc' => ['required', 'string', new RfcFormat($donor->type)],
                'tax_name' => ['required', 'string', 'max:255'],
                'tax_regime' => ['required', new Enum(TaxRegime::class)],
                'tax_postal_code' => ['required', 'string', 'regex:/^\d{5}$/'],
                'cfdi_use' => ['nullable', new Enum(CfdiUse::class)],
            ], [
                'tax_postal_code.regex' => 'El código postal fiscal debe tener 5 dígitos.',
            ], [
                'rfc' => 'RFC', 'tax_name' => 'nombre o razón social fiscal', 'tax_regime' => 'régimen fiscal',
                'tax_postal_code' => 'código postal fiscal', 'cfdi_use' => 'uso de CFDI',
            ])->validate();
        } catch (ValidationException $exception) {
            if ($prefix === '') {
                throw $exception;
            }

            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $key): array => [$prefix.$key => $messages])
                ->all());
        }

        return [
            'rfc' => $data['rfc'],
            'tax_name' => $data['tax_name'],
            'tax_regime' => $data['tax_regime'],
            'tax_postal_code' => $data['tax_postal_code'],
            'cfdi_use' => $data['cfdi_use'] ?? null,
        ];
    }
}
