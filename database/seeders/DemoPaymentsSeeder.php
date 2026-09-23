<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Incidents\OpenPaymentIncident;
use App\Actions\Payments\CreateDonationFromPayment;
use App\Enums\AttemptInitiator;
use App\Enums\FailureCategory;
use App\Enums\IncidentType;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\RetryOwner;
use App\Enums\SubscriptionStatus;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Pagos en línea de demostración (solo local y testing) con la pasarela
 * simulada: nunca con proveedores reales ni credenciales. Incluye un pago
 * exitoso, uno con rechazos, un donativo mensual con una mensualidad cobrada
 * y otra en reintento, y sus incidencias.
 */
class DemoPaymentsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var list<Donor> $donors */
        $donors = Donor::query()->inRandomOrder()->limit(3)->get()->all();
        if (count($donors) < 3) {
            return;
        }

        $succeeded = $this->payment($donors[0], '850.00', PaymentStatus::Succeeded, [[PaymentAttemptStatus::Succeeded, null]]);
        app(CreateDonationFromPayment::class)->handle($succeeded);

        $failed = $this->payment($donors[1], '300.00', PaymentStatus::Failed, [
            [PaymentAttemptStatus::Failed, FailureCategory::InsufficientFunds],
            [PaymentAttemptStatus::Failed, FailureCategory::ProcessingError],
        ]);
        app(OpenPaymentIncident::class)->handle(IncidentType::OneTimePaymentFailed, "payment:{$failed->id}:final_failed", payment: $failed);

        $subscription = Subscription::query()->create([
            'provider' => PaymentProvider::Fake, 'external_id' => 'fake_sub_demo', 'donor_id' => $donors[2]->id,
            'amount' => '250.00', 'currency' => 'MXN', 'interval' => 'monthly', 'status' => SubscriptionStatus::PastDue,
            'retry_owner' => RetryOwner::Provider, 'started_at' => now()->subMonth(), 'next_charge_at' => now()->addDays(3),
            'idempotency_key' => 'demo-'.Str::uuid(),
        ]);

        $first = $this->payment($donors[2], '250.00', PaymentStatus::Succeeded, [[PaymentAttemptStatus::Succeeded, null]], $subscription, now()->subMonth()->startOfMonth()->toDateString());
        app(CreateDonationFromPayment::class)->handle($first);

        $retrying = $this->payment($donors[2], '250.00', PaymentStatus::Pending, [[PaymentAttemptStatus::Failed, FailureCategory::ExpiredCard]], $subscription, now()->startOfMonth()->toDateString());
        $retrying->forceFill(['next_retry_owner' => RetryOwner::Provider, 'next_retry_at' => now()->addDays(3)])->save();
        $attempt = PaymentAttempt::query()->where('payment_id', $retrying->id)->firstOrFail();
        app(OpenPaymentIncident::class)->handle(IncidentType::RecurringAttemptFailed, "attempt:{$attempt->id}:failed", payment: $retrying, attempt: $attempt);
    }

    /**
     * @param  list<array{0: PaymentAttemptStatus, 1: FailureCategory|null}>  $attempts
     */
    private function payment(Donor $donor, string $amount, PaymentStatus $status, array $attempts, ?Subscription $subscription = null, ?string $period = null): Payment
    {
        $payment = Payment::query()->create([
            'provider' => PaymentProvider::Fake,
            'external_id' => 'fake_demo_'.Str::lower(Str::random(10)),
            'kind' => $subscription !== null ? PaymentKind::RecurringCharge : PaymentKind::OneTime,
            'subscription_id' => $subscription?->id,
            'billing_period_start' => $period,
            'donor_id' => $donor->id,
            'amount' => $amount,
            'currency' => 'MXN',
            'status' => $status,
            'provider_status' => $status->value,
            'succeeded_at' => $status === PaymentStatus::Succeeded ? now() : null,
            'failed_at' => $status === PaymentStatus::Failed ? now() : null,
            'idempotency_key' => 'demo-'.Str::uuid(),
        ]);

        foreach ($attempts as $index => [$attemptStatus, $category]) {
            PaymentAttempt::query()->create([
                'payment_id' => $payment->id, 'provider' => PaymentProvider::Fake, 'external_id' => 'fake_demo_ch_'.Str::lower(Str::random(10)),
                'attempt_number' => $index + 1, 'status' => $attemptStatus,
                'initiated_by' => $subscription !== null ? AttemptInitiator::Provider : AttemptInitiator::Donor,
                'failure_category' => $category, 'provider_code' => $category?->value,
                'card_brand' => 'visa', 'card_last4' => '4242', 'provider_created_at' => now(),
            ]);
        }

        return $payment;
    }
}
