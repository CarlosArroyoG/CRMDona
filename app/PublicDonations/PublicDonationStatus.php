<?php

declare(strict_types=1);

namespace App\PublicDonations;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;

/**
 * Estado real de un donativo público, leído de la base de datos (lo que
 * confirmaron el proveedor, su webhook o la conciliación). Nunca de los
 * parámetros con los que el navegador vuelve del proveedor.
 */
final class PublicDonationStatus
{
    public const string CONFIRMED = 'confirmed';

    public const string PROCESSING = 'processing';

    public const string FAILED = 'failed';

    // El pago sigue abierto pero su último intento fue rechazado: se puede reintentar.
    public const string DECLINED = 'declined';

    public const string NOT_STARTED = 'not_started';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(array $payload): string
    {
        $paymentId = is_int($payload['payment_id'] ?? null) ? $payload['payment_id'] : null;
        $subscriptionId = is_int($payload['subscription_id'] ?? null) ? $payload['subscription_id'] : null;

        if ($subscriptionId !== null) {
            $subscription = Subscription::query()->find($subscriptionId);
            $first = Payment::query()->where('subscription_id', $subscriptionId)->orderBy('id')->first();

            return match (true) {
                $subscription === null => self::NOT_STARTED,
                $first?->status === PaymentStatus::Succeeded => self::CONFIRMED,
                $first?->status === PaymentStatus::Failed, in_array($subscription->status, [SubscriptionStatus::Cancelled, SubscriptionStatus::Expired], true) => self::FAILED,
                default => self::PROCESSING,
            };
        }

        if ($paymentId === null) {
            return self::NOT_STARTED;
        }

        $payment = Payment::query()->find($paymentId);
        $lastAttempt = $payment?->attempts()->reorder('attempt_number', 'desc')->first();

        return match (true) {
            $payment === null => self::NOT_STARTED,
            $payment->status === PaymentStatus::Succeeded => self::CONFIRMED,
            in_array($payment->status, [PaymentStatus::Failed, PaymentStatus::Cancelled], true) => self::FAILED,
            $lastAttempt?->status === PaymentAttemptStatus::Failed => self::DECLINED,
            default => self::PROCESSING,
        };
    }
}
