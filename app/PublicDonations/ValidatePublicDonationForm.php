<?php

declare(strict_types=1);

namespace App\PublicDonations;

use App\Actions\Concerns\NormalizesInput;
use App\Actions\Donors\DonorIdentityRules;
use App\Actions\Donors\SaveDonorTaxProfile;
use App\Actions\Payments\ValidateOnlineDonationAmount;
use App\Enums\DonorType;
use App\Models\Campaign;
use App\Models\Donor;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Valida el formulario público en el servidor y produce los datos que se
 * guardan en la sesión. La campaña viene de la URL (no del formulario), el
 * importe se revalida contra las cantidades sugeridas y los límites, y la
 * llave de idempotencia la genera el servidor.
 *
 * Datos fiscales: opcionales ("comprobante a mi nombre"). Solo los que exige
 * un CFDI 4.0 al receptor (RFC, nombre, régimen y CP). Que se capturen no
 * decide la obligación fiscal: la cobertura la resuelve el flujo existente.
 */
class ValidatePublicDonationForm
{
    use NormalizesInput;

    public const string ONE_TIME = 'one_time';

    public const string MONTHLY = 'monthly';

    public const string OTHER_AMOUNT = 'otro';

    public function __construct(
        private readonly PublicDonationPage $page,
        private readonly ValidateOnlineDonationAmount $limits,
        private readonly SaveDonorTaxProfile $taxProfiles,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function handle(array $input, ?Campaign $campaign): array
    {
        $input = $this->normalize($input);
        $frequencies = $this->page->acceptsMonthly() ? [self::ONE_TIME, self::MONTHLY] : [self::ONE_TIME];
        $type = DonorType::tryFrom(is_string($input['donor_type'] ?? null) ? $input['donor_type'] : '');

        $data = Validator::make($input, [
            'frequency' => ['required', Rule::in($frequencies)],
            'amount' => ['required', Rule::in([...$this->page->suggestedAmounts(), self::OTHER_AMOUNT])],
            'custom_amount' => ['required_if:amount,'.self::OTHER_AMOUNT, 'nullable', new MoneyAmount],
            'donor_type' => ['required', Rule::enum(DonorType::class)],
            ...DonorIdentityRules::for($type),
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'wants_tax_receipt' => ['boolean'],
            'privacy_accepted' => ['accepted'],
            'accepts_communications' => ['boolean'],
        ], [
            'privacy_accepted.accepted' => 'Para donar debes aceptar el aviso de privacidad.',
            'custom_amount.required_if' => 'Escribe el importe que quieres donar.',
            'phone.regex' => DonorIdentityRules::PHONE_MESSAGE,
        ], [
            'frequency' => 'frecuencia', 'amount' => 'importe', 'custom_amount' => 'importe', 'donor_type' => 'tipo de donante',
            'first_name' => 'nombre', 'last_name' => 'apellido paterno', 'second_last_name' => 'apellido materno',
            'legal_name' => 'razón social', 'contact_name' => 'persona de contacto', 'email' => 'correo electrónico', 'phone' => 'teléfono',
        ])->validate();

        $amount = Money::normalize($data['amount'] === self::OTHER_AMOUNT ? (string) $data['custom_amount'] : (string) $data['amount']);
        if (Money::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['custom_amount' => 'El importe debe ser mayor que cero.']);
        }

        $gateway = $this->page->gateway() ?? throw ValidationException::withMessages(['amount' => 'Los donativos en línea no están disponibles por ahora.']);
        try {
            $this->limits->handle($gateway, $amount);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$data['amount'] === self::OTHER_AMOUNT ? 'custom_amount' : 'amount' => $exception->errors()['amount'] ?? []]);
        }

        $individual = $type === DonorType::Individual;
        $tax = null;
        if ((bool) ($data['wants_tax_receipt'] ?? false)) {
            $taxInput = [
                'rfc' => is_string($input['rfc'] ?? null) ? mb_strtoupper($input['rfc']) : null,
                'tax_name' => $input['tax_name'] ?? null,
                'tax_regime' => $input['tax_regime'] ?? null,
                'tax_postal_code' => $input['tax_postal_code'] ?? null,
            ];
            $tax = $this->taxProfiles->validate(new Donor(['type' => $type]), $taxInput);
        }

        return [
            'campaign_id' => $campaign?->id,
            'frequency' => $data['frequency'],
            'amount' => $amount,
            'donor' => [
                'type' => $data['donor_type'],
                'first_name' => $individual ? $data['first_name'] : null,
                'last_name' => $individual ? $data['last_name'] : null,
                'second_last_name' => $individual ? ($data['second_last_name'] ?? null) : null,
                'legal_name' => $individual ? null : $data['legal_name'],
                'contact_name' => $individual ? null : ($data['contact_name'] ?? null),
                'email' => Str::lower((string) $data['email']),
                'phone' => $data['phone'] ?? null,
            ],
            'tax' => $tax,
            'accepts_communications' => (bool) ($data['accepts_communications'] ?? false),
            'idempotency_key' => 'public:'.Str::uuid(),
        ];
    }
}
