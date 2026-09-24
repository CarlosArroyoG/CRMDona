<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Contracts\ProcessesOneTimePayments;
use App\Payments\Data\CheckoutResult;
use App\Payments\Data\OneTimePaymentRequest;
use App\Payments\Exceptions\PaymentProviderException;
use App\Payments\GatewayRegistry;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inicia un donativo único en línea (lo usará la página pública).
 *
 * Idempotente por la llave del formulario: un doble clic devuelve el mismo
 * Payment y el proveedor recibe la misma llave, así que tampoco duplica el
 * cobro. El Payment se guarda ANTES de llamar al proveedor y la llamada se
 * hace fuera de toda transacción. Si el proveedor procesó pero la respuesta
 * se perdió (timeout), su notificación trae la referencia del CRM y lo
 * concilia.
 */
class StartOneTimeDonation
{
    use ValidatesOnlineDonationInput;

    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly ValidateOnlineDonationAmount $limits,
        private readonly ApplyProviderSnapshot $applySnapshot,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{payment: Payment, checkout: CheckoutResult}
     *
     * @throws ValidationException
     * @throws PaymentProviderException
     */
    public function handle(array $input): array
    {
        $data = $this->validatedOnlineInput($input, ProcessesOneTimePayments::class, $this->registry, $this->limits);
        /** @var ProcessesOneTimePayments&PaymentGateway $gateway */
        $gateway = $data['gateway'];

        $payment = Payment::query()->createOrFirst(['idempotency_key' => $data['idempotency_key']], [
            'provider' => $data['provider'],
            'kind' => PaymentKind::OneTime,
            'donor_id' => $data['donor']->id,
            'program_id' => $data['program_id'],
            'campaign_id' => $data['campaign_id'],
            'amount' => $data['amount'],
            'currency' => 'MXN',
            'status' => PaymentStatus::Pending,
            'tax_receipt_requested' => $data['tax_receipt_requested'],
        ]);

        if (! $payment->wasRecentlyCreated && ($payment->donor_id !== $data['donor']->id
            || $payment->provider !== $data['provider'] || Money::compare($payment->amount, $data['amount']) !== 0)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Esta solicitud ya se registró con otros datos. Recarga la página e intenta de nuevo.']);
        }

        if ($payment->status !== PaymentStatus::Pending && $payment->status !== PaymentStatus::Processing) {
            return ['payment' => $payment, 'checkout' => new CheckoutResult(paymentExternalId: $payment->external_id)];
        }

        $checkout = $gateway->startOneTimePayment(new OneTimePaymentRequest(
            paymentId: $payment->id,
            idempotencyKey: $payment->idempotency_key,
            amount: $payment->amount,
            currency: $payment->currency,
            description: $this->donationDescription($payment->campaign_id),
            donorEmail: $data['donor']->email,
            returnUrl: config()->string('payments.return_url'),
            cardToken: $data['card_token'],
            paymentMethodId: $data['payment_method_id'],
        ));

        if ($checkout->paymentExternalId !== null) {
            DB::transaction(function () use ($payment, $checkout): void {
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                if ($locked->external_id === null) {
                    $locked->forceFill(['external_id' => $checkout->paymentExternalId])->save();
                }
            });
        }

        if ($checkout->snapshot !== null) {
            $this->applySnapshot->handle($payment->provider, $checkout->snapshot);
        }

        return ['payment' => $payment->refresh(), 'checkout' => $checkout];
    }
}
