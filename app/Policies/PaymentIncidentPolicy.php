<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\IncidentStatus;
use App\Enums\Permission;
use App\Models\PaymentIncident;
use App\Models\User;

/**
 * Incidencias: Administrador y Contador, todas; Coordinador, solo las
 * operativas; Solo lectura, ninguna.
 */
class PaymentIncidentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewIncidents);
    }

    public function view(User $user, PaymentIncident $incident): bool
    {
        return $user->hasPermission(Permission::ViewIncidents) && $user->canHandleIncidentType($incident->type);
    }

    public function viewTechnical(User $user): bool
    {
        return $user->hasPermission(Permission::HandleTechnicalIncidents);
    }

    public function take(User $user, PaymentIncident $incident): bool
    {
        return $this->manage($user, $incident) && $incident->status !== IncidentStatus::Resolved
            && ! ($incident->status === IncidentStatus::Reviewing && $incident->reviewing_by_id === $user->id);
    }

    public function resolve(User $user, PaymentIncident $incident): bool
    {
        return $this->manage($user, $incident) && $incident->status !== IncidentStatus::Resolved;
    }

    public function addNote(User $user, PaymentIncident $incident): bool
    {
        return $this->manage($user, $incident);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PaymentIncident $incident): bool
    {
        return false;
    }

    public function delete(User $user, PaymentIncident $incident): bool
    {
        return false;
    }

    private function manage(User $user, PaymentIncident $incident): bool
    {
        return $user->hasPermission(Permission::ManageIncidents) && $user->canHandleIncidentType($incident->type);
    }
}
