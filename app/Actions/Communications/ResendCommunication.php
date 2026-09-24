<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Enums\Permission;
use App\Models\Communication;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reenvía un agradecimiento (por ejemplo, tras corregir el correo
 * del donante). Crea un registro nuevo con quien lo pidió; el original no se
 * modifica. Las felicitaciones y los envíos históricos de CFDI no se reenvían.
 */
class ResendCommunication
{
    public function __construct(private readonly QueueCommunication $queue) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Communication $original, User $actor): Communication
    {
        if (! $actor->hasPermission(Permission::ResendCommunications)) {
            throw new AuthorizationException('No tienes permiso para reenviar correos.');
        }

        if (! self::canResend($original)) {
            throw ValidationException::withMessages(['communication' => 'Solo se reenvían agradecimientos que ya terminaron (enviados, fallidos, rebotados o no enviados).']);
        }

        return $this->queue->handle(
            $original->kind,
            $original->donor,
            "{$original->kind->value}:resend:{$original->id}:".Str::uuid(),
            donation: $original->donation,
            requestedById: $actor->id,
        );
    }

    public static function canResend(Communication $communication): bool
    {
        return $communication->kind === CommunicationKind::ThankYou
            && in_array($communication->status, [CommunicationStatus::Sent, CommunicationStatus::Failed, CommunicationStatus::Bounced, CommunicationStatus::Skipped], true);
    }
}
