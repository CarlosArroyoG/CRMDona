<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\AuditEvent;
use App\Enums\BulkMessageStatus;
use App\Enums\Permission;
use App\Jobs\PrepareBulkMessage;
use App\Models\BulkMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inicia un envío masivo: exige un borrador probado y al menos un
 * destinatario. El registro de cada correo lo hace la cola
 * (PrepareBulkMessage), porque la audiencia puede ser grande.
 */
class SendBulkMessage
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(BulkMessage $message, User $actor): BulkMessage
    {
        if (! $actor->hasPermission(Permission::SendBulkMessages)) {
            throw new AuthorizationException('No tienes permiso para enviar mensajes masivos.');
        }

        return DB::transaction(function () use ($message, $actor): BulkMessage {
            $message = BulkMessage::query()->lockForUpdate()->findOrFail($message->id);

            if (! $message->isDraft()) {
                throw ValidationException::withMessages(['message' => 'Este envío ya se inició.']);
            }
            if ($message->tested_at === null) {
                throw ValidationException::withMessages(['message' => 'Antes de enviar, manda el correo de prueba y revísalo.']);
            }

            $recipients = $message->audience()->recipients()->count();
            if ($recipients === 0) {
                throw ValidationException::withMessages(['message' => 'Ningún donante cumple los filtros con correo y consentimiento de comunicaciones.']);
            }

            $message->auditAs(AuditEvent::BulkSent)->forceFill([
                'status' => BulkMessageStatus::Preparing,
                'recipients_count' => $recipients,
                'sent_at' => now(),
                'sent_by_id' => $actor->id,
            ])->save();

            PrepareBulkMessage::dispatch($message->id)->afterCommit();

            return $message;
        });
    }
}
