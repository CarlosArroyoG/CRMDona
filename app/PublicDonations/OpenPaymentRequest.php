<?php

declare(strict_types=1);

namespace App\PublicDonations;

use App\Actions\PaymentRequests\ChecksOnlineAvailability;
use App\Enums\DonorType;
use App\Enums\PaymentRequestFrequency;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Convierte el enlace de una solicitud de pago en el mismo payload de sesión
 * que arma el formulario de /donar: de ahí en adelante todo es el flujo
 * público existente (resumen, pago, estado y reintento). Importe, frecuencia,
 * donante y destino salen de la base; nada del navegador.
 *
 * Idempotencia: la llave `payment-request:{id}:{intento}` hace que abrir el enlace
 * varias veces (o en dos navegadores) reutilice el mismo Payment o
 * Subscription y la misma sesión del proveedor. Se pasa a un intento nuevo
 * solo si el anterior terminó sin cobro, si la persona reintenta tras un
 * rechazo o si el anterior quedó abierto más de 24 h: ese es el plazo en que
 * el proveedor conserva la llave y la sesión de pago (Stripe vence la
 * sesión de Checkout a las 24 h), así nunca hay dos sesiones pagables.
 */
final class OpenPaymentRequest
{
    use ChecksOnlineAvailability;

    public function __construct(private readonly PublicDonationPage $page) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function payload(PaymentRequest $request, bool $retry = false): array
    {
        return DB::transaction(function () use ($request, $retry): array {
            $locked = PaymentRequest::query()->with(['donor'])->lockForUpdate()->findOrFail($request->id);
            $this->assertUsable($locked);
            $this->assertPayableOnline($locked->donor, $locked->amount, $locked->frequency, $locked->campaign_id, $locked->program_id);

            if ($retry || $this->needsNewAttempt($locked)) {
                $locked->forceFill(['attempt' => $locked->attempt + 1])->save();
            }

            $donor = $locked->donor;

            return [
                'payment_request_id' => $locked->id,
                'frequency' => $locked->frequency->value,
                'amount' => $locked->amount,
                'campaign_id' => $locked->campaign_id,
                'program_id' => $locked->program_id,
                'donor_id' => $donor->id,
                // Solo para mostrar el resumen; el pago usa donor_id.
                'donor' => [
                    'type' => $donor->type->value,
                    'first_name' => $donor->first_name,
                    'last_name' => $donor->last_name,
                    'second_last_name' => $donor->second_last_name,
                    'legal_name' => $donor->type === DonorType::Organization ? ($donor->legal_name ?? $donor->display_name) : null,
                    'email' => $donor->email,
                ],
                'tax' => $locked->tax_receipt_requested ? true : null,
                'idempotency_key' => $locked->idempotencyKey(),
                'provider' => $this->page->provider()?->value,
                'payment_id' => null,
                'subscription_id' => null,
            ];
        });
    }

    /**
     * Revalidación justo antes de cobrar: la solicitud pudo cancelarse o
     * regenerarse mientras el donante veía el resumen.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function assertPayloadUsable(array $payload): void
    {
        $request = is_int($payload['payment_request_id'] ?? null) ? PaymentRequest::query()->find($payload['payment_request_id']) : null;
        if ($request === null) {
            throw ValidationException::withMessages(['request' => 'Este enlace de pago ya no está disponible.']);
        }

        $this->assertUsable($request);
        if ($request->idempotencyKey() !== ($payload['idempotency_key'] ?? null)) {
            throw ValidationException::withMessages(['request' => 'Este enlace se actualizó. Ábrelo de nuevo desde el correo o mensaje más reciente.']);
        }
    }

    /**
     * Guarda en la solicitud el pago o donativo mensual que se acaba de iniciar.
     */
    public function recordStart(int $requestId, ?Payment $payment, ?Subscription $subscription): void
    {
        DB::transaction(function () use ($requestId, $payment, $subscription): void {
            $request = PaymentRequest::query()->lockForUpdate()->find($requestId);
            if ($request === null || $request->paid_at !== null) {
                return;
            }

            $request->forceFill([
                'payment_id' => $payment->id ?? $request->payment_id,
                'subscription_id' => $subscription->id ?? $request->subscription_id,
            ])->save();
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertUsable(PaymentRequest $request): void
    {
        if (! $request->isUsable()) {
            throw ValidationException::withMessages(['request' => 'Este enlace de pago ya no está disponible: venció, se canceló o ya se pagó. Si necesitas ayuda, comunícate con la Fundación.']);
        }
    }

    private function needsNewAttempt(PaymentRequest $request): bool
    {
        $current = $request->frequency === PaymentRequestFrequency::Monthly
            ? Subscription::query()->where('idempotency_key', $request->idempotencyKey())->first()
            : Payment::query()->where('idempotency_key', $request->idempotencyKey())->first();

        if ($current === null) {
            return false;
        }

        $ended = $current instanceof Payment
            ? in_array($current->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true)
            : in_array($current->status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true)
                || Payment::query()->where('subscription_id', $current->id)->orderBy('id')->value('status') === PaymentStatus::Failed->value;

        return $ended || $current->created_at->lessThanOrEqualTo(now()->subDay());
    }
}
