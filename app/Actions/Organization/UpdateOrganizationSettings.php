<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\DonorType;
use App\Enums\TaxRegime;
use App\Models\OrganizationSetting;
use App\Rules\MoneyAmount;
use App\Rules\RfcFormat;
use App\Support\Money;
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
        'online_donation_min_amount', 'online_donation_max_amount', 'privacy_address', 'privacy_contact_email',
    ];

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(array $input): OrganizationSetting
    {
        $raw = $input;
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
            'online_donation_min_amount' => ['nullable', new MoneyAmount],
            'online_donation_max_amount' => ['nullable', new MoneyAmount],
            'privacy_address' => ['nullable', 'string', 'max:500'],
            'privacy_contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
        ], [
            'tax_postal_code.regex' => 'El código postal fiscal debe tener 5 dígitos.',
        ], [
            'legal_name' => 'razón social', 'tax_regime' => 'régimen fiscal', 'tax_postal_code' => 'código postal fiscal',
            'authorization_number' => 'número de oficio de autorización', 'authorization_date' => 'fecha de autorización',
            'donation_legend' => 'leyenda de donativo', 'logo_path' => 'logotipo', 'email_signature' => 'firma de correo',
            'privacy_notice_url' => 'URL del aviso de privacidad', 'privacy_notice_version' => 'versión del aviso de privacidad',
            'online_donation_min_amount' => 'mínimo por donativo en línea', 'online_donation_max_amount' => 'máximo por donativo en línea',
            'privacy_address' => 'domicilio del responsable', 'privacy_contact_email' => 'correo de privacidad',
        ])->validate();

        foreach (['online_donation_min_amount', 'online_donation_max_amount'] as $limit) {
            if (isset($data[$limit])) {
                $data[$limit] = Money::normalize($data[$limit]);
            }
        }

        if (isset($data['online_donation_min_amount'], $data['online_donation_max_amount'])
            && bccomp($data['online_donation_min_amount'], $data['online_donation_max_amount'], 2) === 1) {
            throw ValidationException::withMessages([
                'online_donation_max_amount' => 'El máximo por donativo en línea no puede ser menor que el mínimo.',
            ]);
        }

        // Interruptores de correos (Fase 4): si no vienen, se conservan.
        $switches = array_map(fn (mixed $value): bool => (bool) $value, Arr::only($raw, ['thank_you_emails_enabled', 'birthday_emails_enabled']));

        return DB::transaction(function () use ($data, $switches): OrganizationSetting {
            $settings = OrganizationSetting::current();
            $settings->fill([...array_merge(array_fill_keys(self::FIELDS, null), $data), ...$switches])->save();

            return $settings;
        });
    }
}
