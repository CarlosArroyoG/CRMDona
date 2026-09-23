<?php

declare(strict_types=1);

use App\Actions\Payments\CreateDonationFromPayment;
use App\Enums\IncidentType;
use App\Jobs\ReconcilePayments;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentIncident;
use App\Models\Program;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('la base de datos protege las invariantes de pagos', function (Closure $insert, string $constraint): void {
    expect(fn () => DB::transaction(fn (): mixed => $insert()))->toThrow(QueryException::class, $constraint);
})->with([
    'moneda distinta de MXN' => [fn () => Payment::factory()->create(['currency' => 'USD']), 'payments_currency_mxn'],
    'importe cero' => [fn () => Payment::factory()->create(['amount' => '0']), 'payments_amount_positive'],
    'proveedor desconocido' => [fn () => DB::table('payments')->insert([
        'provider' => 'paypal', 'kind' => 'one_time', 'donor_id' => Donor::factory()->create()->id, 'amount' => '10',
        'currency' => 'MXN', 'status' => 'pending', 'idempotency_key' => 'x', 'created_at' => now(), 'updated_at' => now(),
    ]), 'payments_provider_valid'],
    'mensualidad sin suscripción' => [fn () => Payment::factory()->create(['kind' => 'recurring_charge', 'billing_period_start' => '2026-09-01']), 'payments_recurring_has_subscription'],
    'exitoso sin fecha de cobro' => [fn () => Payment::factory()->create(['status' => 'succeeded']), 'payments_success_evidence'],
    'destino doble' => [fn () => Payment::factory()->create(['program_id' => Program::factory()->create()->id, 'campaign_id' => Campaign::factory()->create()->id]), 'payments_single_destination'],
    'tarjeta con más de 4 dígitos' => [fn () => DB::table('payment_attempts')->insert([
        'payment_id' => Payment::factory()->create()->id, 'provider' => 'fake', 'attempt_number' => 1, 'status' => 'succeeded',
        'initiated_by' => 'donor', 'card_last4' => '42424', 'created_at' => now(), 'updated_at' => now(),
    ]), 'value too long'],
    'últimos 4 no numéricos' => [fn () => DB::table('payment_attempts')->insert([
        'payment_id' => Payment::factory()->create()->id, 'provider' => 'fake', 'attempt_number' => 1, 'status' => 'succeeded',
        'initiated_by' => 'donor', 'card_last4' => 'abcd', 'created_at' => now(), 'updated_at' => now(),
    ]), 'payment_attempts_card_last4_digits'],
    'intento rechazado sin categoría' => [fn () => DB::table('payment_attempts')->insert([
        'payment_id' => Payment::factory()->create()->id, 'provider' => 'fake', 'attempt_number' => 1, 'status' => 'failed',
        'initiated_by' => 'donor', 'created_at' => now(), 'updated_at' => now(),
    ]), 'payment_attempts_failure_categorized'],
    'cancelada sin origen' => [fn () => Subscription::factory()->create(['status' => 'cancelled', 'cancelled_at' => now()]), 'subscriptions_cancellation_evidence'],
    'otro sin comentario' => [fn () => DB::table('refunds')->insert([
        'payment_id' => Payment::factory()->succeeded()->create()->id, 'provider' => 'fake', 'amount' => '1', 'status' => 'pending',
        'reason' => 'other', 'source' => 'crm', 'requested_by_id' => User::factory()->create()->id,
        'requested_at' => now(), 'idempotency_key' => 'r1', 'created_at' => now(), 'updated_at' => now(),
    ]), 'refunds_other_requires_comment'],
    'reembolso del CRM sin actor' => [fn () => DB::table('refunds')->insert([
        'payment_id' => Payment::factory()->succeeded()->create()->id, 'provider' => 'fake', 'amount' => '1', 'status' => 'pending',
        'reason' => 'donor_request', 'source' => 'crm', 'requested_at' => now(), 'idempotency_key' => 'r2',
        'created_at' => now(), 'updated_at' => now(),
    ]), 'refunds_crm_requires_actor'],
]);

it('los identificadores externos y las llaves de idempotencia son únicos', function (): void {
    $payment = Payment::factory()->create();

    expect(fn () => DB::transaction(fn () => Payment::factory()->create(['external_id' => $payment->external_id])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::transaction(fn () => Payment::factory()->create(['idempotency_key' => $payment->idempotency_key])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('solo un pago exitoso origina donativo', function (): void {
    $payment = Payment::factory()->create();

    expect(fn () => app(CreateDonationFromPayment::class)->handle($payment))->toThrow(LogicException::class)
        ->and(Donation::query()->count())->toBe(0);
});

it('la conciliación crea el donativo que falte de un pago exitoso, una sola vez', function (): void {
    $payment = Payment::factory()->succeeded()->create();

    dispatch_sync(new ReconcilePayments);
    dispatch_sync(new ReconcilePayments);

    expect(Donation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and(PaymentIncident::query()->count())->toBe(0);
});

it('si no puede crear el donativo de un pago exitoso, abre una incidencia', function (): void {
    $payment = Payment::factory()->succeeded()->create();
    // Un donante eliminado a mano en la base de datos es un estado imposible de conciliar solo.
    DB::statement('alter table payments disable trigger all');
    DB::statement('alter table payments drop constraint payments_donor_id_foreign');
    DB::table('payments')->where('id', $payment->id)->update(['donor_id' => 999999]);

    dispatch_sync(new ReconcilePayments);

    expect(PaymentIncident::query()->sole()->type)->toBe(IncidentType::SucceededWithoutDonation);
});
