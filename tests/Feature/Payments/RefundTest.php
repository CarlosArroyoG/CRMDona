<?php

declare(strict_types=1);

use App\Actions\Refunds\RequestRefund;
use App\Enums\DonationStatus;
use App\Enums\IncidentType;
use App\Enums\PaymentStatus;
use App\Enums\RefundReason;
use App\Enums\RefundSource;
use App\Enums\RefundState;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Jobs\ReconcilePayments;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\PaymentIncident;
use App\Models\Refund;
use App\Models\User;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travel;

/**
 * @param  array<string, mixed>  $input
 */
function requestRefund(Payment $payment, array $input = [], ?User $actor = null): Refund
{
    return app(RequestRefund::class)->handle($payment, [
        'amount' => '100.00',
        'reason' => RefundReason::DonorRequest->value,
        'idempotency_key' => 'refund-'.Str::uuid(),
        ...$input,
    ], $actor ?? userWithRole(Role::Accountant));
}

it('reembolso parcial: el pago sigue exitoso, el donativo no cambia y queda auditado', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $accountant = userWithRole(Role::Accountant);
    Auth::login($accountant);

    $refund = requestRefund($payment, ['amount' => '150.00'], $accountant);

    expect($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->external_id)->not->toBeNull()
        ->and($refund->source)->toBe(RefundSource::Crm)
        ->and($refund->requested_by_id)->toBe($accountant->id)
        ->and($refund->provider_reason)->toBe('requested_by_customer')
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->fresh()?->refundState())->toBe(RefundState::Partial)
        ->and(Donation::query()->sole()->status)->toBe(DonationStatus::Confirmed);

    $log = AuditLog::query()->where('auditable_type', 'refund')->where('auditable_id', $refund->id)->where('event', 'created')->sole();
    expect($log->user_id)->toBe($accountant->id)
        ->and($log->new_values)->toMatchArray(['amount' => '150.00', 'reason' => 'donor_request', 'provider' => 'fake', 'status' => 'pending']);
});

it('varios parciales hasta el total; después ya no se puede reembolsar más', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);

    requestRefund($payment, ['amount' => '200.00']);
    requestRefund($payment, ['amount' => '100.00']);
    expect($payment->fresh()?->refundState())->toBe(RefundState::Partial)
        ->and($payment->fresh()?->refundedAmount())->toBe('300.00');

    requestRefund($payment, ['amount' => '200.00']);
    expect($payment->fresh()?->refundState())->toBe(RefundState::Full)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and(fn () => requestRefund($payment, ['amount' => '0.01']))->toThrow(ValidationException::class);
});

it('rechaza exceder el total y no guarda nada', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    requestRefund($payment, ['amount' => '400.00']);

    expect(fn () => requestRefund($payment, ['amount' => '100.01']))->toThrow(ValidationException::class)
        ->and(Refund::query()->count())->toBe(1);
});

it('la base de datos impide que la suma de reembolsos supere el importe aunque se salte la Action', function (): void {
    $payment = Payment::factory()->succeeded()->create(['amount' => '100.00']);
    $row = fn (string $amount, string $key) => [
        'payment_id' => $payment->id, 'provider' => 'fake', 'amount' => $amount, 'status' => 'pending',
        'reason' => 'donor_request', 'source' => 'crm', 'requested_by_id' => userWithRole(Role::Administrator)->id,
        'requested_at' => now(), 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('refunds')->insert($row('60.00', 'k1'));
    expect(fn () => DB::transaction(fn () => DB::table('refunds')->insert($row('40.01', 'k2'))))
        ->toThrow(QueryException::class, 'no puede superar');

    // Un reembolso fallido libera su importe.
    DB::table('refunds')->where('idempotency_key', 'k1')->update(['status' => 'failed']);
    DB::table('refunds')->insert($row('100.00', 'k3'));
    expect(DB::table('refunds')->where('status', 'pending')->sum('amount'))->toEqual('100.00');
});

it('la misma llave dos veces (doble clic o reintento) devuelve el mismo reembolso', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $key = 'refund-'.Str::uuid();

    $first = requestRefund($payment, ['amount' => '100.00', 'idempotency_key' => $key]);
    $second = requestRefund($payment, ['amount' => '100.00', 'idempotency_key' => $key]);

    expect($second->id)->toBe($first->id)
        ->and(Refund::query()->count())->toBe(1)
        ->and(fakeGateway()->refundIdsFor((string) $payment->external_id))->toHaveCount(1)
        ->and(fn () => requestRefund($payment, ['amount' => '120.00', 'idempotency_key' => $key]))->toThrow(ValidationException::class);
});

it('timeout después de que el proveedor reembolsó: queda en proceso y la conciliación lo completa sin duplicar', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    fakeGateway()->willReturn(FakeScenario::TimeoutAfterProcessing);

    $refund = requestRefund($payment, ['amount' => '100.00']);
    expect($refund->status)->toBe(RefundStatus::Pending)->and($refund->external_id)->toBeNull();

    travel(10)->minutes();
    dispatch_sync(new ReconcilePayments);

    expect($refund->fresh()?->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->fresh()?->external_id)->not->toBeNull()
        ->and(fakeGateway()->refundIdsFor((string) $payment->external_id))->toHaveCount(1);
});

it('un reembolso pendiente aparta saldo mientras se resuelve', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    fakeGateway()->willReturn(FakeScenario::TimeoutAfterProcessing);
    requestRefund($payment, ['amount' => '450.00']);

    expect($payment->fresh()?->refundableAmount())->toBe('50.00')
        ->and(fn () => requestRefund($payment, ['amount' => '60.00']))->toThrow(ValidationException::class);
});

it('si el proveedor rechaza el reembolso queda fallido, libera el saldo y abre incidencia', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    fakeGateway()->willReturn(FakeScenario::Rejected);

    $refund = requestRefund($payment, ['amount' => '100.00']);

    expect($refund->status)->toBe(RefundStatus::Failed)
        ->and($refund->failure_reason)->toContain('rechazó')
        ->and($payment->fresh()?->refundableAmount())->toBe('500.00')
        ->and(PaymentIncident::query()->sole()->type)->toBe(IncidentType::RefundFailed);
});

it('un reembolso exitoso que falla después descuenta el importe y alerta', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $refund = requestRefund($payment, ['amount' => '100.00']);

    fakeGateway()->setRefundStatus((string) $refund->external_id, RefundStatus::Failed);
    deliverFakeWebhook('refund.failed', 'refund', (string) $refund->external_id);

    expect($refund->fresh()?->status)->toBe(RefundStatus::Failed)
        ->and($payment->fresh()?->refundState())->toBe(RefundState::None)
        ->and(PaymentIncident::query()->where('type', IncidentType::RefundFailed->value)->count())->toBe(1);
});

it('registra reembolsos hechos directamente en el panel del proveedor', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $refundId = fakeGateway()->providerRefund((string) $payment->external_id, '80.00');

    deliverFakeWebhook('charge.refunded', 'payment', (string) $payment->external_id);
    deliverFakeWebhook('refund.created', 'refund', $refundId);

    $refund = Refund::query()->sole();
    expect($refund->source)->toBe(RefundSource::Provider)
        ->and($refund->reason)->toBe(RefundReason::ProviderInitiated)
        ->and($refund->requested_by_id)->toBeNull()
        ->and($refund->status)->toBe(RefundStatus::Succeeded)
        ->and($payment->fresh()?->refundedAmount())->toBe('80.00');
});

it('motivo obligatorio; "Otro" exige comentario; el motivo interno no se envía como texto libre', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);

    expect(fn () => requestRefund($payment, ['reason' => null]))->toThrow(ValidationException::class)
        ->and(fn () => requestRefund($payment, ['reason' => 'otro_inventado']))->toThrow(ValidationException::class)
        ->and(fn () => requestRefund($payment, ['reason' => 'provider_initiated']))->toThrow(ValidationException::class)
        ->and(fn () => requestRefund($payment, ['reason' => RefundReason::Other->value]))->toThrow(ValidationException::class);

    $other = requestRefund($payment, ['reason' => RefundReason::Other->value, 'reason_comment' => 'Acuerdo con el donante']);
    $admin = requestRefund($payment, ['reason' => RefundReason::AdministrativeError->value]);

    expect($other->reason_comment)->toBe('Acuerdo con el donante')
        ->and($other->provider_reason)->toBeNull()
        ->and($admin->provider_reason)->toBeNull()
        ->and(requestRefund($payment, ['reason' => RefundReason::DuplicateCharge->value])->provider_reason)->toBe('duplicate');
});

it('solo Administrador y Contador solicitan reembolsos', function (Role $role, bool $allowed): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $attempt = fn () => requestRefund($payment, [], userWithRole($role));

    $allowed ? expect($attempt()->status)->toBe(RefundStatus::Succeeded) : expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    'Administrador' => [Role::Administrator, true],
    'Contador' => [Role::Accountant, true],
    'Coordinador' => [Role::FundraisingCoordinator, false],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('no reembolsa pagos que no son exitosos ni con disputa abierta', function (): void {
    $pending = startFakeDonation([], FakeScenario::Pending);
    expect(fn () => requestRefund($pending))->toThrow(ValidationException::class);

    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    $disputeId = fakeGateway()->openDispute((string) $payment->external_id);
    deliverFakeWebhook('charge.dispute.created', 'dispute', $disputeId);

    expect(fn () => requestRefund($payment))->toThrow(ValidationException::class, 'disputa abierta');
});
