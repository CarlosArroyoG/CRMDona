<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequests;

use App\Actions\Communications\QueueCommunication;
use App\Enums\CommunicationKind;
use App\Enums\Permission;
use App\Models\Communication;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Envía por correo el enlace de UNA solicitud concreta a SU donante, a
 * petición de una persona. Es transaccional (como el agradecimiento), no un
 * envío masivo: no existe forma de mandarlo a una lista. El enlace se arma
 * al enviar y no se guarda en el historial.
 */
class SendPaymentRequest
{
    public function __construct(private readonly QueueCommunication $queue) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PaymentRequest $request, User $actor): Communication
    {
        if (! $actor->hasPermission(Permission::RequestPayments)) {
            throw new AuthorizationException('No tienes permiso para enviar solicitudes de pago.');
        }

        if (! $request->isUsable()) {
            throw ValidationException::withMessages(['request' => 'La solicitud ya no está vigente. Regenera el enlace antes de enviarlo.']);
        }

        if (blank($request->donor->email)) {
            throw ValidationException::withMessages(['request' => 'El donante no tiene correo electrónico. Copia el enlace y envíalo por otro medio.']);
        }

        return $this->queue->handle(
            CommunicationKind::PaymentRequest,
            $request->donor,
            "payment_request:{$request->id}:v{$request->token_version}:".Str::uuid(),
            requestedById: $actor->id,
            paymentRequest: $request,
        );
    }
}
