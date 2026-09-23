<?php

declare(strict_types=1);

namespace App\Actions\Incidents;

use App\Enums\AuditEvent;
use App\Enums\IncidentStatus;
use App\Models\PaymentIncident;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Nueva o En revisión → Resuelta, con la resolución por escrito. Una
 * incidencia resuelta conserva su resolución; un hecho nuevo sobre el mismo
 * pago abre otra incidencia.
 */
class ResolveIncident
{
    use AuthorizesIncidentWork;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PaymentIncident $incident, ?string $resolution, User $actor): PaymentIncident
    {
        $this->authorizeWork($actor, $incident);

        /** @var array{resolution: string} $data */
        $data = Validator::make(
            ['resolution' => $resolution !== null ? trim($resolution) : null],
            ['resolution' => ['required', 'string', 'min:5', 'max:2000']],
            [],
            ['resolution' => 'resolución'],
        )->validate();

        return DB::transaction(function () use ($incident, $data, $actor): PaymentIncident {
            $locked = PaymentIncident::query()->lockForUpdate()->findOrFail($incident->id);

            if ($locked->status === IncidentStatus::Resolved) {
                throw ValidationException::withMessages(['incident' => TakeIncidentForReview::ALREADY_RESOLVED]);
            }

            $locked->auditAs(AuditEvent::IncidentResolved)->forceFill([
                'status' => IncidentStatus::Resolved,
                'resolved_by_id' => $actor->id,
                'resolved_at' => now(),
                'resolution' => $data['resolution'],
            ])->save();

            return $locked;
        });
    }
}
