<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Communications\QueueCommunication;
use App\Communications\MessageComposer;
use App\Enums\CommunicationStatus;
use App\Mail\DonorMessage;
use App\Mail\Outgoing\OutgoingMailConfig;
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

    public function handle(MessageComposer $composer): void
    {
        $claimed = DB::table('communications')->where('id', $this->communicationId)
            ->where(fn ($query) => $query->whereIn('status', [CommunicationStatus::Queued->value, CommunicationStatus::Failed->value])
                ->orWhere(fn ($stuck) => $stuck->where('status', CommunicationStatus::Sending->value)
                    ->where('updated_at', '<=', now()->subMinutes(config()->integer('communications.sending.stuck_after_minutes')))))
            ->update(['status' => CommunicationStatus::Sending->value, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $communication = Communication::query()->with(['donor', 'donation.donor'])->findOrFail($this->communicationId);
        $donor = $communication->donor;

        $skip = $communication->kind->isHistorical()
            ? 'El CRM ya no envía CFDI; contabilidad los emite y entrega fuera del sistema.'
            : QueueCommunication::skipReason($communication->kind, $donor);
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
                'last_error' => SensitiveData::safeText(app(OutgoingMailConfig::class)->scrub($exception->getMessage()), 500),
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
            'last_error' => null,
        ])->save();
    }
}
