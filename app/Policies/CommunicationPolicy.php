<?php

declare(strict_types=1);

namespace App\Policies;

use App\Actions\Communications\ResendCommunication;
use App\Enums\Permission;
use App\Models\Communication;
use App\Models\User;

/**
 * Registro de envíos: consultar y reenviar (Administrador, Coordinador,
 * Contador). Nadie crea, edita ni borra registros a mano.
 */
class CommunicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewCommunications);
    }

    public function view(User $user, Communication $communication): bool
    {
        return $user->hasPermission(Permission::ViewCommunications);
    }

    public function resend(User $user, Communication $communication): bool
    {
        return $user->hasPermission(Permission::ResendCommunications) && ResendCommunication::canResend($communication);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Communication $communication): bool
    {
        return false;
    }

    public function delete(User $user, Communication $communication): bool
    {
        return false;
    }
}
