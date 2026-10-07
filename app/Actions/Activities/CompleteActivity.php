<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\ActivityStatus;
use App\Enums\AuditEvent;
use App\Enums\Permission;
use App\Models\DonorActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Programada → Completada. Registra el resultado y, si aplica, la próxima
 * acción sugerida (nota libre; no crea una tarea por sí misma).
 */
class CompleteActivity
{
    use NormalizesInput;

    public const string NOT_SCHEDULED = 'Solo se completan actividades programadas.';

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(DonorActivity $activity, array $input, User $actor): DonorActivity
    {
        if (! $actor->hasPermission(Permission::ManageDonorActivities)) {
            throw new AuthorizationException('No tienes permiso para completar actividades.');
        }

        $input = $this->normalize($input);
        $data = Validator::make($input, [
            'result' => ['nullable', 'string', 'max:2000'],
            'next_action' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($activity, $data, $actor): DonorActivity {
            $locked = DonorActivity::query()->lockForUpdate()->findOrFail($activity->id);

            if (! $locked->isScheduled()) {
                throw ValidationException::withMessages(['activity' => self::NOT_SCHEDULED]);
            }

            $locked->auditAs(AuditEvent::Completed)->forceFill([
                'status' => ActivityStatus::Completed,
                'completed_at' => now(),
                'completed_by_id' => $actor->id,
                'result' => $data['result'] ?? null,
                'next_action' => $data['next_action'] ?? null,
            ])->save();

            return $locked;
        });
    }
}
