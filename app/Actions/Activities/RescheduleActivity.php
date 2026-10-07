<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\DonorActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Cambia la fecha de una actividad programada. No cambia su estado ni su
 * responsable.
 */
class RescheduleActivity
{
    public const string NOT_SCHEDULED = 'Solo se reprograman actividades programadas.';

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(DonorActivity $activity, string $scheduledAt, User $actor): DonorActivity
    {
        if (! $actor->hasPermission(Permission::ManageDonorActivities)) {
            throw new AuthorizationException('No tienes permiso para reprogramar actividades.');
        }

        $data = Validator::make(['scheduled_at' => $scheduledAt], [
            'scheduled_at' => ['required', 'date'],
        ])->validate();

        return DB::transaction(function () use ($activity, $data): DonorActivity {
            $locked = DonorActivity::query()->lockForUpdate()->findOrFail($activity->id);

            if (! $locked->isScheduled()) {
                throw ValidationException::withMessages(['activity' => self::NOT_SCHEDULED]);
            }

            $locked->auditAs(AuditEvent::Rescheduled)->forceFill([
                'scheduled_at' => $data['scheduled_at'],
            ])->save();

            return $locked;
        });
    }
}
