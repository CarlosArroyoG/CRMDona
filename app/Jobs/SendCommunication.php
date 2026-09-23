<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Communications\QueueCfdiDelivery;
use App\Actions\Communications\QueueCommunication;
use App\Communications\MessageComposer;
use App\Enums\CfdiStatus;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Mail\DonorMessage;
use App\Models\Communication;
use App\Support\SensitiveData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envía un correo registrado en `communications`.
 *
 * - Solo un proceso lo toma (queued/failed → sending con UPDATE condicional;
 *   un "Enviando" abandonado se retoma después de unos minutos).
 * - Un envío ya "Enviado" nunca se repite: los reintentos de la cola no
 *   duplican el agradecimiento.
 * - Un error del servidor de correo deja "Fallido" y la cola reintenta con
 *   espera; un error de un mensaje no afecta a los demás.
 */
class SendCommunication implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public readonly int $communicationId)
    {
        $this->tries = config()->integer('communications.sending.tries');
        /** @var list<int> $backoff */
        $backoff = config()->array('communications.sending.backoff');
        $this->backoff = $backoff;
    }

    public function handle(MessageComposer $composer, QueueCfdiDelivery $cfdiDelivery): void
    {
        $claimed = DB::table('communications')->where('id', $this->communicationId)
            ->where(fn ($query) => $query->whereIn('status', [CommunicationStatus::Queued->value, CommunicationStatus::Failed->value])
                ->orWhere(fn ($stuck) => $stuck->where('status', CommunicationStatus::Sending->value)
                    ->where('updated_at', '<=', now()->subMinutes(config()->integer('communications.sending.stuck_after_minutes')))))
            ->update(['status' => CommunicationStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $communication = Communication::query()->with(['donor', 'donation.donor', 'cfdi.donation'])->findOrFail($this->communicationId);
        $donor = $communication->donor;

        $skip = QueueCommunication::skipReason($communication->kind, $donor);
        if ($skip !== null) {
            $communication->forceFill(['status' => CommunicationStatus::Skipped, 'skip_reason' => $skip])->save();

            return;
        }

        try {
            $message = $composer->compose($communication);
            Mail::to((string) $donor->email)->send(new DonorMessage($message));
        } catch (Throwable $exception) {
            $communication->forceFill([
                'status' => CommunicationStatus::Failed,
                'last_error' => SensitiveData::safeText($exception->getMessage(), 500),
            ])->save();

            throw $exception;
        }

        $communication->forceFill([
            'status' => CommunicationStatus::Sent,
            'sent_at' => now(),
            'subject' => mb_substr($message->subject, 0, 200),
            'recipient' => Communication::maskEmail($donor->email),
            'attachments' => $message->attachmentNames(),
            'used_fallback_template' => $message->usedFallback,
            'cfdi_id' => $communication->cfdi_id ?? $message->cfdiId,
            'last_error' => null,
        ])->save();

        // El CFDI pudo timbrarse mientras salía el agradecimiento sin él.
        if ($communication->kind === CommunicationKind::ThankYou && $message->cfdiId === null) {
            $cfdi = $communication->donation?->activeCfdi();
            if ($cfdi !== null && $cfdi->status === CfdiStatus::Stamped) {
                $cfdiDelivery->handle($cfdi);
            }
        }
    }
}
