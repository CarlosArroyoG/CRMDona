<?php

declare(strict_types=1);

use App\Actions\Refunds\RequestRefund;
use App\Enums\AuditSource;
use App\Enums\RefundReason;
use App\Enums\Role;
use App\Jobs\ReconcilePayments;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Program;
use App\Models\User;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;

it('un cambio hecho por una persona queda con su usuario y procedencia "Usuario"', function (): void {
    $user = userWithRole(Role::Administrator);
    actingAs($user);
    Program::factory()->create();

    $log = AuditLog::query()->where('auditable_type', 'program')->sole();
    expect($log->user_id)->toBe($user->id)->and($log->source)->toBe(AuditSource::User);
});

it('los cambios que provoca un webhook quedan sin usuario y con procedencia "webhook"; sin usuario "sistema"', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);

    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id);

    $donationLog = AuditLog::query()->where('auditable_type', 'donation')->sole();
    $paymentUpdate = AuditLog::query()->where('auditable_type', 'payment')->where('event', 'updated')->latest('id')->firstOrFail();

    expect($donationLog->source)->toBe(AuditSource::Webhook)
        ->and($donationLog->user_id)->toBeNull()
        ->and($paymentUpdate->source)->toBe(AuditSource::Webhook)
        ->and(User::query()->where('email', 'like', '%sistema%')->exists())->toBeFalse()
        ->and(Donation::query()->sole()->registered_by_id)->toBeNull();
});

it('la conciliación programada deja procedencia "sincronización"', function (): void {
    $payment = startFakeDonation(['amount' => '500'], FakeScenario::Success);
    fakeGateway()->willReturn(FakeScenario::TimeoutAfterProcessing);
    $refund = app(RequestRefund::class)->handle($payment, [
        'amount' => '50', 'reason' => RefundReason::DonorRequest->value, 'idempotency_key' => 'r-'.Str::uuid(),
    ], userWithRole(Role::Accountant));

    travel(10)->minutes();
    dispatch_sync(new ReconcilePayments);

    $log = AuditLog::query()->where('auditable_type', 'refund')->where('auditable_id', $refund->id)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($log->source)->toBe(AuditSource::Synchronization)->and($log->user_id)->toBeNull();
});

it('los comandos de consola quedan con procedencia "Consola"', function (): void {
    Program::factory()->create();

    expect(AuditLog::query()->where('auditable_type', 'program')->sole()->source)->toBe(AuditSource::Console);
});
