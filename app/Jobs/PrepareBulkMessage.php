<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Communications\QueueCommunication;
use App\Enums\BulkMessageStatus;
use App\Enums\CommunicationKind;
use App\Models\BulkMessage;
use App\Models\Donor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registra un correo por destinatario de un envío masivo y los reparte en la
 * cola a `communications.bulk.per_minute` por minuto.
 *
 * - Llave `bulk_message:{envío}:donor:{donante}`: si el Job se repite, nadie
 *   recibe el correo dos veces.
 * - La audiencia se evalúa aquí, al enviar (no al redactar).
 * - Si el envío se detiene, deja de registrar destinatarios.
 */
class PrepareBulkMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int CHUNK = 200;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $bulkMessageId) {}

    public function uniqueId(): string
    {
        return (string) $this->bulkMessageId;
    }

    public function handle(QueueCommunication $queue): void
    {
        $message = BulkMessage::query()->find($this->bulkMessageId);
        if ($message === null || $message->status !== BulkMessageStatus::Preparing) {
            return;
        }

        $perMinute = max(1, config()->integer('communications.bulk.per_minute'));
        $queued = $message->communications()->count();
        $start = now();

        $message->audience()->recipients()->orderBy('id')->chunkById(self::CHUNK, function (Collection $donors) use ($message, $queue, $perMinute, $start, &$queued): bool {
            if ($message->refresh()->status !== BulkMessageStatus::Preparing) {
                return false;
            }

            /** @var Donor $donor */
            foreach ($donors as $donor) {
                $communication = $queue->handle(
                    CommunicationKind::BulkMessage,
                    $donor,
                    "bulk_message:{$message->id}:donor:{$donor->id}",
                    requestedById: $message->sent_by_id,
                    bulkMessage: $message,
                    sendAt: $start->copy()->addMinutes(intdiv($queued, $perMinute)),
                );

                if ($communication->wasRecentlyCreated) {
                    $queued++;
                }
            }

            return true;
        });

        DB::transaction(function (): void {
            $message = BulkMessage::query()->lockForUpdate()->findOrFail($this->bulkMessageId);
            if ($message->status === BulkMessageStatus::Preparing) {
                $message->forceFill([
                    'status' => BulkMessageStatus::Sent,
                    'recipients_count' => $message->communications()->count(),
                ])->save();
            }
        });
    }
}
