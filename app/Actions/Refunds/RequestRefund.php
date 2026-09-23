<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Concerns\NormalizesInput;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\RefundReason;
use App\Enums\RefundSource;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Payments\Contracts\ProcessesRefunds;
use App\Payments\GatewayRegistry;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Solicita un reembolso (fase-2-diseno-pagos.md §9). Defensa en profundidad:
 *
 * 1. Validación de dominio y permiso (Administrador o Contador).
 * 2. Transacción con el Payment bloqueado: dos solicitudes simultáneas se
 *    atienden una tras otra y la segunda ve el saldo ya apartado.
 * 3. Llave de idempotencia única: un doble clic o un reintento devuelve el
 *    mismo Refund y el proveedor recibe la misma llave.
 * 4. Trigger `refunds_within_payment_amount` en PostgreSQL.
 *
 * El Refund se guarda `pending` antes de llamar al proveedor, y la llamada
 * ocurre fuera de la transacción. Nunca modifica el donativo.
 */
class RequestRefund
{
    use NormalizesInput;

    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly SubmitRefundToProvider $submit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  amount, reason, reason_comment, idempotency_key
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Payment $payment, array $input, User $actor): Refund
    {
        if (! $actor->hasPermission(Permission::RequestRefunds)) {
            throw new AuthorizationException('No tienes permiso para solicitar reembolsos.');
        }

        $input = $this->normalize($input);
        $data = Validator::make($input, [
            'amount' => ['required', new MoneyAmount],
            'reason' => ['required', Rule::in(array_keys(RefundReason::selectable()))],
            'reason_comment' => ['nullable', 'string', 'max:1000', 'required_if:reason,'.RefundReason::Other->value],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9_:\-]+$/'],
        ], [
            'reason_comment.required_if' => 'Describe el motivo cuando eliges "Otro".',
        ], [
            'amount' => 'importe a reembolsar', 'reason' => 'motivo', 'reason_comment' => 'comentario',
        ])->validate();

        $gateway = $this->registry->getForUserAction($payment->provider);
        if (! $gateway instanceof ProcessesRefunds) {
            throw ValidationException::withMessages(['payment' => 'Este proveedor no permite reembolsos desde el CRM.']);
        }

        $amount = Money::normalize($data['amount']);
        $reason = RefundReason::from($data['reason']);

        $refund = DB::transaction(function () use ($payment, $data, $amount, $reason, $actor, $gateway): Refund {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $existing = Refund::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing !== null) {
                if ($existing->payment_id !== $locked->id || Money::compare($existing->amount, $amount) !== 0) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Esta solicitud ya se registró con otros datos. Cierra el formulario y vuelve a abrirlo.']);
                }

                return $existing;
            }

            if ($locked->status !== PaymentStatus::Succeeded) {
                throw ValidationException::withMessages(['payment' => 'Solo se reembolsan pagos exitosos.']);
            }

            if ($locked->hasOpenDispute()) {
                throw ValidationException::withMessages(['payment' => 'El pago tiene una disputa abierta: no puede reembolsarse mientras se resuelve.']);
            }

            $available = $locked->refundableAmount();
            if (bccomp($amount, $available, 2) > 0) {
                throw ValidationException::withMessages(['amount' => 'El importe excede lo que queda por reembolsar ('.Money::format($available).' MXN).']);
            }

            return Refund::query()->create([
                'payment_id' => $locked->id,
                'provider' => $locked->provider,
                'amount' => $amount,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
                'reason_comment' => $data['reason_comment'] ?? null,
                'provider_reason' => $gateway->providerRefundReason($reason),
                'source' => RefundSource::Crm,
                'requested_by_id' => $actor->id,
                'requested_at' => now(),
                'idempotency_key' => $data['idempotency_key'],
            ]);
        });

        return $this->submit->handle($refund);
    }
}
