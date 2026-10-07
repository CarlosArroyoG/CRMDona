<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Enums\ActivityStatus;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\DonorActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Programada → Cancelada. Una actividad ya completada no se cancela (el
 * historial de lo que ocurrió no se edita).
 */
class CancelActivity
{
    public const string NOT_SCHEDULED = 'Solo se cancelan actividades programadas.';

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(DonorActivity $activity, User $actor): DonorActivity
    {
        if (! $actor->hasPermission(Permission::ManageDonorActivities)) {
            throw new AuthorizationException('No tienes permiso para cancelar actividades.');
        }

        return DB::transaction(function () use ($activity, $actor): DonorActivity {
            $locked = DonorActivity::query()->lockForUpdate()->findOrFail($activity->id);

            if (! $locked->isScheduled()) {
                throw ValidationException::withMessages(['activity' => self::NOT_SCHEDULED]);
            }

            $locked->auditAs(AuditEvent::Cancelled)->forceFill([
                'status' => ActivityStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor->id,
            ])->save();

            return $locked;
        });
    }
}
