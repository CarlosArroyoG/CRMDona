<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\AuditEvent;
use App\Enums\BulkMessageStatus;
use App\Enums\Permission;
use App\Models\BulkMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Detiene un envío masivo en curso. Los correos que ya salieron no se pueden
 * recuperar; los que seguían en cola quedan "No enviado" al llegar su turno
 * (SendCommunication vuelve a revisar el estado del envío).
 */
class StopBulkMessage
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(BulkMessage $message, User $actor): BulkMessage
    {
        if (! $actor->hasPermission(Permission::SendBulkMessages)) {
            throw new AuthorizationException('No tienes permiso para detener envíos masivos.');
        }

        return DB::transaction(function () use ($message, $actor): BulkMessage {
            $message = BulkMessage::query()->lockForUpdate()->findOrFail($message->id);

            if (! $message->isStoppable()) {
                throw ValidationException::withMessages(['message' => 'Solo se detiene un envío que está preparando o enviando.']);
            }

            $message->auditAs(AuditEvent::BulkStopped)->forceFill([
                'status' => BulkMessageStatus::Stopped,
                'stopped_at' => now(),
                'stopped_by_id' => $actor->id,
            ])->save();

            return $message;
        });
    }
}
