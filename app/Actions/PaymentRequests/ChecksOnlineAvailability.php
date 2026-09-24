<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequests;

use App\Actions\Payments\ValidateOnlineDonationAmount;
use App\Enums\PaymentRequestFrequency;
use App\Enums\ProgramStatus;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\Program;
use App\Payments\Contracts\ProcessesRecurringPayments;
use App\PublicDonations\PublicDonationPage;
use Illuminate\Validation\ValidationException;

/**
 * Lo que debe cumplirse para cobrar en línea una solicitud de pago, al
 * prepararla y otra vez al abrir el enlace: el servidor revalida todo con los
 * datos guardados, nunca con los del navegador. Reutiliza las mismas reglas
 * de la página pública (proveedor disponible, límites y destino vigente).
 */
trait ChecksOnlineAvailability
{
    /**
     * @throws ValidationException
     */
    protected function assertPayableOnline(Donor $donor, string $amount, PaymentRequestFrequency $frequency, ?int $campaignId, ?int $programId): void
    {
        if ($donor->isArchived()) {
            throw ValidationException::withMessages(['donor_id' => 'El donante está archivado. Reactívalo antes de solicitarle un pago.']);
        }

        $page = app(PublicDonationPage::class);
        if ($page->unavailableReason(null, false) !== null || ($gateway = $page->gateway()) === null) {
            throw ValidationException::withMessages(['amount' => 'Los pagos en línea no están disponibles por ahora (proveedor de pago o aviso de privacidad sin configurar).']);
        }

        if ($frequency === PaymentRequestFrequency::Monthly && ! $gateway instanceof ProcessesRecurringPayments) {
            throw ValidationException::withMessages(['frequency' => 'El proveedor de pago activo no admite donativos mensuales.']);
        }

        app(ValidateOnlineDonationAmount::class)->handle($gateway, $amount);

        if ($campaignId !== null && ! (Campaign::query()->with('program')->find($campaignId)?->acceptsDonations() ?? false)) {
            throw ValidationException::withMessages(['campaign_id' => 'Esta campaña no está recibiendo donativos en este momento.']);
        }

        if ($programId !== null && Program::query()->find($programId)?->status !== ProgramStatus::Active) {
            throw ValidationException::withMessages(['program_id' => 'Este programa no está activo.']);
        }
    }
}
