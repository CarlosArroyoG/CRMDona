<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\DonorType;
use App\Enums\TaxRegime;
use App\Models\OrganizationSetting;
use App\Rules\RfcFormat;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class UpdateOrganizationSettings
{
    use NormalizesInput;

    private const array FIELDS = [
        'legal_name', 'rfc', 'tax_regime', 'tax_postal_code', 'authorization_number', 'authorization_date',
        'donation_legend', 'logo_path', 'email_signature', 'privacy_notice_url', 'privacy_notice_version',
    ];

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input): OrganizationSetting
    {
        $input = $this->normalize(Arr::only($input, self::FIELDS));
        if (is_string($input['rfc'] ?? null)) {
            $input['rfc'] = mb_strtoupper($input['rfc']);
        }

        $data = Validator::make($input, [
            'legal_name' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', new RfcFormat(DonorType::Organization)],
            'tax_regime' => ['nullable', new Enum(TaxRegime::class)],
            'tax_postal_code' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'authorization_number' => ['nullable', 'string', 'max:100'],
            'authorization_date' => ['nullable', 'date', 'before_or_equal:today'],
            'donation_legend' => ['nullable', 'string', 'max:2000'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'email_signature' => ['nullable', 'string', 'max:2000'],
            'privacy_notice_url' => ['nullable', 'url:https,http', 'max:255', 'required_with:privacy_notice_version'],
            'privacy_notice_version' => ['nullable', 'string', 'max:50', 'required_with:privacy_notice_url'],
        ], [
            'tax_postal_code.regex' => 'El código postal fiscal debe tener 5 dígitos.',
        ], [
            'legal_name' => 'razón social', 'tax_regime' => 'régimen fiscal', 'tax_postal_code' => 'código postal fiscal',
            'authorization_number' => 'número de oficio de autorización', 'authorization_date' => 'fecha de autorización',
            'donation_legend' => 'leyenda de donativo', 'logo_path' => 'logotipo', 'email_signature' => 'firma de correo',
            'privacy_notice_url' => 'URL del aviso de privacidad', 'privacy_notice_version' => 'versión del aviso de privacidad',
        ])->validate();

        return DB::transaction(function () use ($data): OrganizationSetting {
            $settings = OrganizationSetting::current();
            $settings->fill(array_merge(array_fill_keys(self::FIELDS, null), $data))->save();

            return $settings;
        });
    }
}
