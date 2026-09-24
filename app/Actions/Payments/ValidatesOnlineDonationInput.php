<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\PaymentProvider;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\Program;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Exceptions\GatewayNotAvailableException;
use App\Payments\GatewayRegistry;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Reglas comunes al iniciar un donativo en línea único o mensual.
 */
trait ValidatesOnlineDonationInput
{
    use NormalizesInput;

    /**
     * @param  array<string, mixed>  $input
     * @param  class-string  $capability
     * @return array{gateway: PaymentGateway, provider: PaymentProvider, donor: Donor, campaign_id: int|null, program_id: int|null, amount: numeric-string, idempotency_key: string, card_token: string|null, payment_method_id: string|null, tax_receipt_requested: bool}
     *
     * @throws ValidationException
     */
    protected function validatedOnlineInput(array $input, string $capability, GatewayRegistry $registry, ValidateOnlineDonationAmount $limits): array
    {
        $input = $this->normalize($input);

        $data = Validator::make($input, [
            'provider' => ['required', new Enum(PaymentProvider::class)],
            'donor_id' => ['required', 'integer', Rule::exists(Donor::class, 'id')->whereNull('archived_at')],
            'campaign_id' => ['nullable', 'integer', Rule::exists(Campaign::class, 'id'), 'prohibits:program_id'],
            'program_id' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'amount' => ['required', new MoneyAmount],
            // Generada por el navegador al abrir el formulario: un doble clic reutiliza la misma.
            'idempotency_key' => ['required', 'string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9_:\-]+$/'],
            'card_token' => ['nullable', 'string', 'max:255'],
            'payment_method_id' => ['nullable', 'string', 'max:50'],
            // El donante pidió CFDI: se informa a Contabilidad en el aviso del donativo (el CRM no lo emite).
            'tax_receipt_requested' => ['boolean'],
        ], [], [
            'provider' => 'proveedor de pago', 'donor_id' => 'donante', 'campaign_id' => 'campaña', 'program_id' => 'programa',
            'amount' => 'importe', 'idempotency_key' => 'llave de idempotencia',
        ])->validate();

        $provider = PaymentProvider::from($data['provider']);

        try {
            $gateway = $registry->get($provider);
        } catch (GatewayNotAvailableException) {
            throw ValidationException::withMessages(['provider' => 'Ese proveedor de pago no está disponible.']);
        }

        if (! $gateway instanceof $capability) {
            throw ValidationException::withMessages(['provider' => 'Ese proveedor de pago no admite este tipo de donativo.']);
        }

        $amount = Money::normalize($data['amount']);
        $limits->handle($gateway, $amount);

        return [
            'gateway' => $gateway,
            'provider' => $provider,
            'donor' => Donor::query()->findOrFail((int) $data['donor_id']),
            'campaign_id' => isset($data['campaign_id']) ? (int) $data['campaign_id'] : null,
            'program_id' => isset($data['program_id']) ? (int) $data['program_id'] : null,
            'amount' => $amount,
            'idempotency_key' => $data['idempotency_key'],
            'card_token' => $data['card_token'] ?? null,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'tax_receipt_requested' => (bool) ($data['tax_receipt_requested'] ?? false),
        ];
    }

    protected function donationDescription(?int $campaignId): string
    {
        $campaign = $campaignId !== null ? Campaign::query()->whereKey($campaignId)->value('name') : null;

        return 'Donativo'.(is_string($campaign) ? " — {$campaign}" : '');
    }
}
