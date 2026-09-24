<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequests;

use App\Enums\PaymentRequestFrequency;
use App\Enums\PaymentRequestStatus;
use App\Enums\Permission;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\PaymentRequest;
use App\Models\Program;
use App\Models\User;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

/**
 * Prepara un cobro con tarjeta (desde "Crear donativo"). No crea un
 * donativo: el Donation nace solo del pago exitoso que confirma el
 * proveedor. El donante escribe su tarjeta en la página segura del
 * proveedor, en este equipo o con el enlace; el CRM nunca la ve (MOTO fuera
 * de alcance).
 */
class CreatePaymentRequest
{
    use ChecksOnlineAvailability;

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $input, User $actor): PaymentRequest
    {
        if (! $actor->hasPermission(Permission::RequestPayments)) {
            throw new AuthorizationException('No tienes permiso para preparar cobros con tarjeta.');
        }

        $data = Validator::make($input, [
            'donor_id' => ['required', 'integer', Rule::exists(Donor::class, 'id')->whereNull('archived_at')],
            'amount' => ['required', new MoneyAmount],
            'frequency' => ['required', new Enum(PaymentRequestFrequency::class)],
            'campaign_id' => ['nullable', 'integer', Rule::exists(Campaign::class, 'id'), 'prohibits:program_id'],
            'program_id' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'tax_receipt_requested' => ['boolean'],
        ], [
            'donor_id.exists' => 'El donante no existe o está archivado. Reactívalo antes de solicitarle un pago.',
            'campaign_id.prohibits' => 'Elige una campaña o un programa, no ambos: el programa de la campaña se toma automáticamente.',
        ], [
            'donor_id' => 'donante', 'amount' => 'importe', 'frequency' => 'frecuencia', 'campaign_id' => 'campaña', 'program_id' => 'programa',
        ])->validate();

        $donor = Donor::query()->findOrFail((int) $data['donor_id']);
        $amount = Money::normalize((string) $data['amount']);
        $frequency = $data['frequency'] instanceof PaymentRequestFrequency ? $data['frequency'] : PaymentRequestFrequency::from((string) $data['frequency']);
        $campaignId = isset($data['campaign_id']) ? (int) $data['campaign_id'] : null;
        $programId = isset($data['program_id']) ? (int) $data['program_id'] : null;

        $this->assertPayableOnline($donor, $amount, $frequency, $campaignId, $programId);

        // 40 caracteres alfanuméricos de una fuente criptográfica (random_bytes): el formato que ya acepta /donar.
        $token = Str::random(40);

        return PaymentRequest::query()->create([
            'token_hash' => PaymentRequest::hashToken($token),
            'token' => $token,
            'donor_id' => $donor->id,
            'amount' => $amount,
            'frequency' => $frequency,
            'campaign_id' => $campaignId,
            'program_id' => $programId,
            'tax_receipt_requested' => (bool) ($data['tax_receipt_requested'] ?? false),
            'status' => PaymentRequestStatus::Open,
            'expires_at' => now()->addDays(PaymentRequest::VALID_DAYS),
            'created_by_id' => $actor->id,
        ]);
    }
}
