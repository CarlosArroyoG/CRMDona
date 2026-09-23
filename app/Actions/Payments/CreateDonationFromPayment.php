<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use App\Enums\DonationKind;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\PaymentStatus;
use App\Models\Donation;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Crea el donativo de un pago exitoso, una sola vez. Se llama con el Payment
 * ya bloqueado dentro de la transacción que lo marcó `succeeded`; el índice
 * único `donations.payment_id` es la última barrera.
 *
 * El donativo nace confirmado y sin actor humano: su evidencia es el pago,
 * sus intentos y la notificación del proveedor. No existe usuario "sistema".
 */
class CreateDonationFromPayment
{
    public function handle(Payment $payment): Donation
    {
        if ($payment->status !== PaymentStatus::Succeeded || $payment->succeeded_at === null) {
            throw new LogicException('Solo un pago exitoso origina un donativo.');
        }

        return DB::transaction(function () use ($payment): Donation {
            $existing = Donation::query()->where('payment_id', $payment->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            $donation = new Donation;
            $donation->forceFill([
                'origin' => DonationOrigin::Online,
                'payment_id' => $payment->id,
                'donor_id' => $payment->donor_id,
                'program_id' => $payment->program_id,
                'campaign_id' => $payment->campaign_id,
                'kind' => DonationKind::Monetary,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'received_on' => $payment->succeeded_at->copy()->setTimezone(config()->string('app.timezone'))->toDateString(),
                'status' => DonationStatus::Confirmed,
                'confirmed_at' => $payment->succeeded_at,
                'tax_receipt_requested' => false,
            ])->save();

            return $donation;
        });
    }
}
