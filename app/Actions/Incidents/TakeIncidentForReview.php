<?php

declare(strict_types=1);

namespace App\Actions\Incidents;

use App\Enums\AuditEvent;
use App\Enums\IncidentStatus;
use App\Models\PaymentIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nueva → En revisión (o reasignar una en revisión a quien la toma).
 */
class TakeIncidentForReview
{
    use AuthorizesIncidentWork;

    public const string ALREADY_RESOLVED = 'La incidencia ya está resuelta.';

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PaymentIncident $incident, User $actor): PaymentIncident
    {
        $this->authorizeWork($actor, $incident);

        return DB::transaction(function () use ($incident, $actor): PaymentIncident {
            $locked = PaymentIncident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($locked->status === IncidentStatus::Resolved) {
                throw ValidationException::withMessages(['incident' => self::ALREADY_RESOLVED]);
            }

            if ($locked->status === IncidentStatus::Reviewing && $locked->reviewing_by_id === $actor->id) {
                return $locked;
            }

            $locked->auditAs(AuditEvent::IncidentTaken)->forceFill([
                'status' => IncidentStatus::Reviewing,
                'reviewing_by_id' => $actor->id,
                'reviewing_started_at' => now(),
            ])->save();

            return $locked;
        });
    }
}
