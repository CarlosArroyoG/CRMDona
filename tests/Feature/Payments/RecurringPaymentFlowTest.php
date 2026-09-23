<?php

declare(strict_types=1);

use App\Enums\AttemptInitiator;
use App\Enums\CancellationSource;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\RetryOwner;
use App\Enums\SubscriptionStatus;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentIncident;
use App\Payments\Gateways\FakeScenario;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('inicia un donativo mensual: suscripción activa, primera mensualidad y su donativo', function (): void {
    $subscription = startFakeMonthlyDonation(['amount' => '300'], FakeScenario::Success);

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->retry_owner)->toBe(RetryOwner::Provider)
        ->and($subscription->external_id)->not->toBeNull()
        ->and($subscription->started_at)->not->toBeNull();

    $payment = Payment::query()->sole();
    expect($payment->kind)->toBe(PaymentKind::RecurringCharge)
        ->and($payment->subscription_id)->toBe($subscription->id)
        ->and($payment->billing_period_start?->toDateString())->toBe(now()->startOfMonth()->toDateString())
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and(Donation::query()->sole()->payment_id)->toBe($payment->id);
});

it('cada mensualidad exitosa es un pago distinto con su propio donativo', function (): void {
    $subscription = startFakeMonthlyDonation();
    $next = CarbonImmutable::now()->startOfMonth()->addMonth();

    $chargeId = fakeGateway()->chargeSubscription((string) $subscription->external_id, $next, FakeScenario::Success);
    deliverFakeWebhook('invoice.paid', 'payment', $chargeId)->assertOk();

    expect(Payment::query()->where('subscription_id', $subscription->id)->count())->toBe(2)
        ->and(Donation::query()->count())->toBe(2)
        ->and(Payment::query()->where('external_id', $chargeId)->sole()->billing_period_start?->toDateString())->toBe($next->toDateString());
});

it('un cobro mensual rechazado no crea donativo, no cancela la suscripción y alerta desde el primer rechazo', function (): void {
    $subscription = startFakeMonthlyDonation();
    $next = CarbonImmutable::now()->startOfMonth()->addMonth();
    fakeGateway()->setSubscriptionStatus((string) $subscription->external_id, SubscriptionStatus::PastDue);

    $chargeId = fakeGateway()->chargeSubscription((string) $subscription->external_id, $next, FakeScenario::InsufficientFunds);
    deliverFakeWebhook('invoice.payment_failed', 'payment', $chargeId);
    deliverFakeWebhook('customer.subscription.updated', 'subscription', (string) $subscription->external_id);

    $payment = Payment::query()->where('external_id', $chargeId)->sole();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->next_retry_owner)->toBe(RetryOwner::Provider)
        ->and($payment->next_retry_at)->not->toBeNull()
        ->and(Donation::query()->count())->toBe(1)
        ->and($subscription->fresh()?->status)->toBe(SubscriptionStatus::PastDue);

    $incident = PaymentIncident::query()->sole();
    expect($incident->type)->toBe(IncidentType::RecurringAttemptFailed)
        ->and($incident->severity)->toBe(IncidentSeverity::Warning)
        ->and($incident->subscription_id)->toBe($subscription->id);
});

it('los reintentos del proveedor quedan como intentos del mismo pago; la recuperación crea el donativo', function (): void {
    $subscription = startFakeMonthlyDonation();
    $next = CarbonImmutable::now()->startOfMonth()->addMonth();
    $external = (string) $subscription->external_id;

    $chargeId = fakeGateway()->chargeSubscription($external, $next, FakeScenario::Declined);
    deliverFakeWebhook('invoice.payment_failed', 'payment', $chargeId);
    fakeGateway()->chargeSubscription($external, $next, FakeScenario::InsufficientFunds);
    deliverFakeWebhook('invoice.payment_failed', 'payment', $chargeId);
    fakeGateway()->chargeSubscription($external, $next, FakeScenario::Success);
    deliverFakeWebhook('invoice.paid', 'payment', $chargeId);

    $payment = Payment::query()->where('external_id', $chargeId)->sole();
    $attempts = PaymentAttempt::query()->where('payment_id', $payment->id)->orderBy('attempt_number')->get();

    expect($attempts)->toHaveCount(3)
        ->and($attempts->pluck('attempt_number')->all())->toBe([1, 2, 3])
        ->and($attempts->every(fn (PaymentAttempt $a): bool => $a->initiated_by === AttemptInitiator::Provider))->toBeTrue()
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and(Donation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and(PaymentIncident::query()->where('type', IncidentType::RecurringAttemptFailed->value)->count())->toBe(2);
});

it('cuando el proveedor deja de reintentar, la mensualidad queda fallida con incidencia crítica y la suscripción no se cancela', function (): void {
    $subscription = startFakeMonthlyDonation();
    $next = CarbonImmutable::now()->startOfMonth()->addMonth();

    $chargeId = fakeGateway()->chargeSubscription((string) $subscription->external_id, $next, FakeScenario::Declined, exhausted: true);
    deliverFakeWebhook('invoice.payment_failed', 'payment', $chargeId);

    expect(Payment::query()->where('external_id', $chargeId)->sole()->status)->toBe(PaymentStatus::Failed)
        ->and($subscription->fresh()?->status)->toBe(SubscriptionStatus::Active)
        ->and(PaymentIncident::query()->where('type', IncidentType::RecurringPaymentFailed->value)->sole()->severity)
        ->toBe(IncidentSeverity::Critical);
});

it('una mensualidad fallida puede recuperarse después (fallido → exitoso) y entonces crea su donativo', function (): void {
    $subscription = startFakeMonthlyDonation();
    $next = CarbonImmutable::now()->startOfMonth()->addMonth();
    $chargeId = fakeGateway()->chargeSubscription((string) $subscription->external_id, $next, FakeScenario::Declined, exhausted: true);
    deliverFakeWebhook('invoice.payment_failed', 'payment', $chargeId);

    fakeGateway()->chargeSubscription((string) $subscription->external_id, $next, FakeScenario::Success);
    deliverFakeWebhook('invoice.paid', 'payment', $chargeId);

    expect(Payment::query()->where('external_id', $chargeId)->sole()->status)->toBe(PaymentStatus::Succeeded)
        ->and(Donation::query()->count())->toBe(2);
});

it('si el proveedor cancela la suscripción por su cuenta, el CRM lo refleja y abre incidencia', function (): void {
    $subscription = startFakeMonthlyDonation();
    fakeGateway()->setSubscriptionStatus((string) $subscription->external_id, SubscriptionStatus::Cancelled);

    deliverFakeWebhook('customer.subscription.deleted', 'subscription', (string) $subscription->external_id);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->cancellation_source)->toBe(CancellationSource::Provider)
        ->and($subscription->cancelled_at)->not->toBeNull()
        ->and($subscription->cancelled_by_id)->toBeNull()
        ->and(PaymentIncident::query()->where('type', IncidentType::SubscriptionCancelledByProvider->value)->count())->toBe(1);
});

it('una transición no permitida no se aplica y abre una incidencia de revisión', function (): void {
    $subscription = startFakeMonthlyDonation();
    fakeGateway()->setSubscriptionStatus((string) $subscription->external_id, SubscriptionStatus::Cancelled);
    deliverFakeWebhook('customer.subscription.deleted', 'subscription', (string) $subscription->external_id);

    fakeGateway()->setSubscriptionStatus((string) $subscription->external_id, SubscriptionStatus::Active);
    deliverFakeWebhook('customer.subscription.updated', 'subscription', (string) $subscription->external_id);

    expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::Cancelled)
        ->and(PaymentIncident::query()->where('type', IncidentType::StateInconsistency->value)->count())->toBe(1);
});

it('no permite dos mensualidades para el mismo periodo', function (): void {
    $subscription = startFakeMonthlyDonation();

    expect(fn () => DB::transaction(fn () => Payment::query()->create([
        'provider' => 'fake', 'kind' => 'recurring_charge', 'subscription_id' => $subscription->id,
        'billing_period_start' => now()->startOfMonth()->toDateString(), 'donor_id' => $subscription->donor_id,
        'amount' => '300', 'status' => 'pending', 'idempotency_key' => 'duplicado-periodo',
    ])))->toThrow(UniqueConstraintViolationException::class);
});
