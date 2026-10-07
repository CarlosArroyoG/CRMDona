<?php

declare(strict_types=1);

namespace App\Actions\DonorAssignments;

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Donor;
use App\Models\DonorAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Asigna o reasigna al responsable (procurador) de un donante. Reasignar
 * nunca edita ni borra la fila vigente: la cierra (`ended_at`) y crea una
 * nueva. Solo Administrador o Coordinador pueden ser el responsable
 * asignado (confirmado con la institución: la procuración es su rol
 * dedicado).
 */
class AssignDonorResponsible
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Donor $donor, int $userId, ?string $note, User $actor): DonorAssignment
    {
        if (! $actor->hasPermission(Permission::AssignDonorResponsible)) {
            throw new AuthorizationException('No tienes permiso para asignar al responsable de un donante.');
        }

        $data = Validator::make(['user_id' => $userId, 'note' => $note], [
            'user_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id')->where(fn ($query) => $query->whereIn('role', [
                    Role::Administrator->value, Role::FundraisingCoordinator->value,
                ])),
            ],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'user_id.exists' => 'Solo un Administrador o un Coordinador de procuración puede ser responsable de un donante.',
        ])->validate();

        return DB::transaction(function () use ($donor, $data, $actor): DonorAssignment {
            $current = DonorAssignment::query()
                ->where('donor_id', $donor->id)
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($current !== null) {
                if ($current->user_id === (int) $data['user_id']) {
                    throw ValidationException::withMessages(['user_id' => 'Ese usuario ya es el responsable vigente de este donante.']);
                }

                $current->forceFill(['ended_at' => now()])->save();
            }

            $assignment = (new DonorAssignment)->forceFill([
                'donor_id' => $donor->id,
                'user_id' => (int) $data['user_id'],
                'assigned_by_id' => $actor->id,
                'started_at' => now(),
                'note' => $data['note'] ?? null,
            ]);
            $assignment->auditAs(AuditEvent::ResponsibleAssigned)->save();

            return $assignment;
        });
    }
}
