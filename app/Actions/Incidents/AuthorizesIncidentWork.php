<?php

declare(strict_types=1);

namespace App\Actions\Incidents;

use App\Enums\Permission;
use App\Models\PaymentIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Atienden incidencias Administrador, Coordinador y Contador; las técnicas,
 * solo Administrador y Contador.
 */
trait AuthorizesIncidentWork
{
    /**
     * @throws AuthorizationException
     */
    protected function authorizeWork(User $actor, PaymentIncident $incident): void
    {
        if (! $actor->hasPermission(Permission::ManageIncidents) || ! $actor->canHandleIncidentType($incident->type)) {
            throw new AuthorizationException('No tienes permiso para atender esta incidencia.');
        }
    }
}
