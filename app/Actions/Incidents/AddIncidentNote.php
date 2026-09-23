<?php

declare(strict_types=1);

namespace App\Actions\Incidents;

use App\Models\PaymentIncident;
use App\Models\PaymentIncidentNote;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Agrega una nota de seguimiento. Las notas no se editan ni se eliminan
 * (trigger); también se pueden agregar a una incidencia resuelta.
 */
class AddIncidentNote
{
    use AuthorizesIncidentWork;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PaymentIncident $incident, ?string $body, User $actor): PaymentIncidentNote
    {
        $this->authorizeWork($actor, $incident);

        /** @var array{body: string} $data */
        $data = Validator::make(
            ['body' => $body !== null ? trim($body) : null],
            ['body' => ['required', 'string', 'min:2', 'max:5000']],
            [],
            ['body' => 'nota'],
        )->validate();

        return PaymentIncidentNote::query()->create([
            'payment_incident_id' => $incident->id,
            'user_id' => $actor->id,
            'body' => $data['body'],
        ]);
    }
}
