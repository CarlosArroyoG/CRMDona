<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequests;

use App\Enums\AuditEvent;
use App\Enums\PaymentRequestStatus;
use App\Enums\Permission;
use App\Models\PaymentRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cancelar y regenerar una solicitud de pago. Solo aplica a solicitudes
 * abiertas (vigentes o vencidas): una pagada o cancelada ya no cambia.
 * Regenerar crea un token nuevo, invalida el anterior y reinicia la vigencia
 * de 7 días. Ninguna de las dos toca un pago que ya esté en curso con el
 * proveedor: si ese pago se confirma, la solicitud queda pagada igualmente.
 */
class ManagePaymentRequest
{
    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancel(PaymentRequest $request, User $actor): PaymentRequest
    {
        return $this->locked($request, $actor, function (PaymentRequest $locked) use ($actor): PaymentRequest {
            $locked->auditAs(AuditEvent::Cancelled)->forceFill([
                'status' => PaymentRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_id' => $actor->id,
            ])->save();

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function regenerate(PaymentRequest $request, User $actor): PaymentRequest
    {
        return $this->locked($request, $actor, function (PaymentRequest $locked): PaymentRequest {
            $token = Str::random(40);
            $locked->auditAs(AuditEvent::Regenerated)->forceFill([
                'token_hash' => PaymentRequest::hashToken($token),
                'token' => $token,
                'token_version' => $locked->token_version + 1,
                'expires_at' => now()->addDays(PaymentRequest::VALID_DAYS),
            ])->save();

            return $locked;
        });
    }

    /**
     * @param  callable(PaymentRequest): PaymentRequest  $change
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function locked(PaymentRequest $request, User $actor, callable $change): PaymentRequest
    {
        if (! $actor->hasPermission(Permission::RequestPayments)) {
            throw new AuthorizationException('No tienes permiso para gestionar solicitudes de pago.');
        }

        return DB::transaction(function () use ($request, $change): PaymentRequest {
            $locked = PaymentRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($locked->status !== PaymentRequestStatus::Open) {
                throw ValidationException::withMessages(['request' => 'Esta solicitud ya está pagada o cancelada; no se puede modificar.']);
            }

            return $change($locked);
        });
    }
}
