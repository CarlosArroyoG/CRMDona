<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\BulkMessageStatus;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Jobs\SendCommunication;
use App\Models\BulkMessage;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\PaymentRequest;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registra un correo una sola vez por `dedupe_key` y lo encola. Un reintento
 * del mismo hecho (confirmación, scheduler repetido) devuelve el
 * registro existente sin volver a enviar. Si el donante no tiene correo o no
 * dio consentimiento (cuando aplica), queda "No enviado" con el motivo.
 *
 * `$sendAt` reparte en el tiempo los correos de un envío masivo (límite por
 * minuto del proveedor SMTP).
 */
class QueueCommunication
{
    public function handle(
        CommunicationKind $kind,
        Donor $donor,
        string $dedupeKey,
        ?Donation $donation = null,
        ?int $requestedById = null,
        ?PaymentRequest $paymentRequest = null,
        ?BulkMessage $bulkMessage = null,
        ?DateTimeInterface $sendAt = null,
    ): Communication {
        $existing = Communication::query()->where('dedupe_key', $dedupeKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $skip = self::skipReason($kind, $donor, $paymentRequest, $bulkMessage);

        try {
            $communication = DB::transaction(fn (): Communication => Communication::query()->create([
                'kind' => $kind,
                'donor_id' => $donor->id,
                'donation_id' => $donation?->id,
                'payment_request_id' => $paymentRequest?->id,
                'bulk_message_id' => $bulkMessage?->id,
                'dedupe_key' => $dedupeKey,
                'status' => $skip === null ? CommunicationStatus::Queued : CommunicationStatus::Skipped,
                'skip_reason' => $skip,
                'recipient' => Communication::maskEmail($donor->email),
                'requested_by_id' => $requestedById,
            ]));
        } catch (UniqueConstraintViolationException) {
            return Communication::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
        }

        if ($skip === null) {
            SendCommunication::dispatch($communication->id)->delay($sendAt)->afterCommit();
        }

        return $communication;
    }

    public static function skipReason(CommunicationKind $kind, Donor $donor, ?PaymentRequest $paymentRequest = null, ?BulkMessage $bulkMessage = null): ?string
    {
        return match (true) {
            blank($donor->email) => 'El donante no tiene correo electrónico.',
            // El enlace de una solicitud pagada, cancelada, vencida o regenerada ya no sirve: no se envía.
            $kind === CommunicationKind::PaymentRequest && ($paymentRequest === null || ! $paymentRequest->isUsable()) => 'La solicitud de pago ya no está vigente (pagada, cancelada o vencida).',
            // Un envío masivo detenido no manda los correos que seguían en cola.
            $kind === CommunicationKind::BulkMessage && ($bulkMessage === null || $bulkMessage->status === BulkMessageStatus::Stopped) => 'El envío masivo se detuvo antes de mandar este correo.',
            $kind->requiresConsent() && ! $donor->accepts_communications => 'El donante no acepta recibir comunicaciones.',
            $kind->requiresConsent() && $donor->isArchived() => 'El donante está archivado.',
            default => null,
        };
    }
}
