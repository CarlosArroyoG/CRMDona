<?php

declare(strict_types=1);

namespace App\Actions\PaymentRequests;

use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;

/**
 * Marca pagada la solicitud de la que salió un pago exitoso. Solo se llama
 * desde SyncPayment, con el estado que confirmó el proveedor (webhook o
 * consulta): nunca por el regreso del navegador. La solicitud se reconoce
 * por la llave de idempotencia del pago o de su donativo mensual, así
 * también funciona después de un reintento. Si alguien la canceló o venció
 * mientras el pago estaba en curso, el dinero sí llegó: queda pagada.
 */
class CompletePaymentRequest
{
    public function handle(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Succeeded) {
            return;
        }

        $requestId = PaymentRequest::idFromIdempotencyKey($payment->idempotency_key)
            ?? PaymentRequest::idFromIdempotencyKey($payment->subscription?->idempotency_key);
        if ($requestId === null) {
            return;
        }

        DB::transaction(function () use ($requestId, $payment): void {
            $request = PaymentRequest::query()->lockForUpdate()->find($requestId);
            if ($request === null || $request->status === PaymentRequestStatus::Paid) {
                return;
            }

            $request->forceFill([
                'status' => PaymentRequestStatus::Paid,
                'paid_at' => $payment->succeeded_at ?? now(),
                'payment_id' => $payment->subscription_id === null ? $payment->id : $request->payment_id,
                'subscription_id' => $payment->subscription_id ?? $request->subscription_id,
            ])->save();
        });
    }
}
