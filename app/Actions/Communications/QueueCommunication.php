<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Jobs\SendCommunication;
use App\Models\Cfdi;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\Donor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registra un correo una sola vez por `dedupe_key` y lo encola. Un reintento
 * del mismo hecho (confirmación, timbrado, scheduler repetido) devuelve el
 * registro existente sin volver a enviar. Si el donante no tiene correo o no
 * dio consentimiento (cuando aplica), queda "No enviado" con el motivo.
 */
class QueueCommunication
{
    public function handle(
        CommunicationKind $kind,
        Donor $donor,
        string $dedupeKey,
        ?Donation $donation = null,
        ?Cfdi $cfdi = null,
        ?int $requestedById = null,
        int $delaySeconds = 0,
    ): Communication {
        $existing = Communication::query()->where('dedupe_key', $dedupeKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $skip = self::skipReason($kind, $donor);

        try {
            $communication = DB::transaction(fn (): Communication => Communication::query()->create([
                'kind' => $kind,
                'donor_id' => $donor->id,
                'donation_id' => $donation?->id,
                'cfdi_id' => $cfdi?->id,
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
            SendCommunication::dispatch($communication->id)->delay($delaySeconds)->afterCommit();
        }

        return $communication;
    }

    public static function skipReason(CommunicationKind $kind, Donor $donor): ?string
    {
        return match (true) {
            blank($donor->email) => 'El donante no tiene correo electrónico.',
            $kind->requiresConsent() && ! $donor->accepts_communications => 'El donante no acepta recibir comunicaciones.',
            $kind->requiresConsent() && $donor->isArchived() => 'El donante está archivado.',
            default => null,
        };
    }
}
