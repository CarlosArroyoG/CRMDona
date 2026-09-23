<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\CfdiStatus;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Models\Cfdi;
use App\Models\Communication;
use Illuminate\Support\Facades\DB;

/**
 * Envía al donante un CFDI recién timbrado, una sola vez (`cfdi:{id}`) y sin
 * repetir el agradecimiento. No hace nada si:
 * - el agradecimiento del donativo aún no sale (lo adjuntará él; al salir
 *   vuelve a llamar aquí por si el CFDI se timbró mientras se enviaba);
 * - otro correo ya incluyó o va a incluir este CFDI.
 */
class QueueCfdiDelivery
{
    public function __construct(private readonly QueueCommunication $queue) {}

    public function handle(Cfdi $cfdi): ?Communication
    {
        if ($cfdi->status !== CfdiStatus::Stamped) {
            return null;
        }

        return DB::transaction(function () use ($cfdi): ?Communication {
            $thankYou = Communication::query()->where('dedupe_key', "thank_you:donation:{$cfdi->donation_id}")->lockForUpdate()->first();
            if ($thankYou !== null && in_array($thankYou->status, [CommunicationStatus::Queued, CommunicationStatus::Sending], true)) {
                return null;
            }

            $alreadyIncluded = Communication::query()->where('cfdi_id', $cfdi->id)
                ->whereIn('status', [CommunicationStatus::Queued->value, CommunicationStatus::Sending->value, CommunicationStatus::Sent->value])
                ->exists();
            if ($alreadyIncluded) {
                return null;
            }

            return $this->queue->handle(CommunicationKind::Cfdi, $cfdi->donation->donor, "cfdi:{$cfdi->id}", donation: $cfdi->donation, cfdi: $cfdi);
        });
    }
}
