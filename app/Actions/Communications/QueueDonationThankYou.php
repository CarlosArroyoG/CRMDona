<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\CommunicationKind;
use App\Enums\DonationStatus;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\OrganizationSetting;

/**
 * Agradecimiento al confirmarse un donativo (manual o en línea, incluida cada
 * mensualidad). Uno por donativo (`thank_you:donation:{id}`). Sale de
 * inmediato con el recibo simple adjunto: nunca espera un CFDI (el CRM no los
 * emite; docs/tecnico/cfdi-externo.md).
 *
 * `$force` = pedido por una persona aunque el agradecimiento automático esté
 * apagado en Organización.
 */
class QueueDonationThankYou
{
    public function __construct(
        private readonly IssueDonationReceipt $receipts,
        private readonly QueueCommunication $queue,
    ) {}

    public function handle(Donation $donation, bool $force = false, ?int $requestedById = null): ?Communication
    {
        if ($donation->status !== DonationStatus::Confirmed || (! $force && ! OrganizationSetting::current()->thank_you_emails_enabled)) {
            return null;
        }

        $this->receipts->handle($donation);

        return $this->queue->handle(
            CommunicationKind::ThankYou,
            $donation->donor,
            "thank_you:donation:{$donation->id}",
            donation: $donation,
            requestedById: $requestedById,
        );
    }
}
