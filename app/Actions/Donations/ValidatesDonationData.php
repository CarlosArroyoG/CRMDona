<?php

declare(strict_types=1);

namespace App\Actions\Donations;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\DonationKind;
use App\Enums\PaymentMethod;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\Program;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Reglas comunes al registrar y al editar un donativo pendiente.
 */
trait ValidatesDonationData
{
    use NormalizesInput;

    /**
     * Atributos listos para el modelo: el importe como string exacto y los
     * campos que no aplican al tipo de donativo en null.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function validatedAttributes(array $input): array
    {
        $input = $this->normalize($input);
        $inKind = ($input['kind'] ?? null) === DonationKind::InKind->value;

        $data = Validator::make($input, [
            'donor_id' => ['required', 'integer', Rule::exists(Donor::class, 'id')->whereNull('archived_at')],
            'campaign_id' => ['nullable', 'integer', Rule::exists(Campaign::class, 'id'), 'prohibits:program_id'],
            'program_id' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'kind' => ['required', new Enum(DonationKind::class)],
            'payment_method' => [Rule::requiredIf(! $inKind), 'nullable', new Enum(PaymentMethod::class)],
            'amount' => ['required', new MoneyAmount],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'in_kind_description' => [Rule::requiredIf($inKind), 'nullable', 'string', 'max:2000'],
            'tax_receipt_requested' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [
            'donor_id.exists' => 'El donante no existe o está archivado. Reactívalo antes de registrarle donativos.',
            'campaign_id.prohibits' => 'Elige una campaña o un programa, no ambos: el programa de la campaña se toma automáticamente.',
        ], [
            'donor_id' => 'donante', 'campaign_id' => 'campaña', 'program_id' => 'programa', 'kind' => 'tipo de donativo',
            'payment_method' => 'forma de pago', 'amount' => $inKind ? 'valor asignado' : 'importe',
            'received_on' => 'fecha de recepción', 'reference' => 'referencia',
            'in_kind_description' => 'descripción de lo donado', 'notes' => 'notas',
        ])->validate();

        return [
            'donor_id' => (int) $data['donor_id'],
            'campaign_id' => isset($data['campaign_id']) ? (int) $data['campaign_id'] : null,
            'program_id' => isset($data['program_id']) ? (int) $data['program_id'] : null,
            'kind' => $data['kind'],
            'payment_method' => $inKind ? null : $data['payment_method'],
            'amount' => Money::normalize($data['amount']),
            'received_on' => $data['received_on'],
            'reference' => $data['reference'] ?? null,
            'in_kind_description' => $inKind ? $data['in_kind_description'] : null,
            'tax_receipt_requested' => (bool) ($data['tax_receipt_requested'] ?? false),
            'notes' => $data['notes'] ?? null,
        ];
    }
}
