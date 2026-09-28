<?php

declare(strict_types=1);

namespace App\Actions\Communications;

use App\Enums\Permission;
use App\Models\BulkMessage;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Elimina un borrador que nunca se envió. Un envío iniciado es evidencia de
 * a quién se escribió: no se borra (se puede detener).
 */
class DeleteBulkMessage
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(BulkMessage $message, User $actor): void
    {
        if (! $actor->hasPermission(Permission::SendBulkMessages)) {
            throw new AuthorizationException('No tienes permiso para eliminar envíos masivos.');
        }

        if (! $message->isDraft() || $message->communications()->exists()) {
            throw ValidationException::withMessages(['message' => 'Solo se elimina un borrador que no se ha enviado.']);
        }

        $message->delete();
    }
}
