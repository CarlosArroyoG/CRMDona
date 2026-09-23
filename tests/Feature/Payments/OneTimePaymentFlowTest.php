<?php

declare(strict_types=1);

use App\Actions\Payments\StartOneTimeDonation;
use App\Enums\AttemptInitiator;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\FailureCategory;
use App\Enums\IncidentType;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentIncident;
use App\Models\Program;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

it('un pago exitoso crea un solo donativo en línea, confirmado y sin actor humano', function (): void {
    $payment = startFakeDonation(['amount' => '750.50'], FakeScenario::Success);

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->external_id)->not->toBeNull()
        ->and($payment->succeeded_at)->not->toBeNull()
        ->and(PaymentAttempt::query()->where('payment_id', $payment->id)->count())->toBe(1);

    $donation = Donation::query()->sole();
    expect($donation->origin)->toBe(DonationOrigin::Online)
        ->and($donation->payment_id)->toBe($payment->id)
        ->and($donation->status)->toBe(DonationStatus::Confirmed)
        ->and($donation->amount)->toBe('750.50')
        ->and($donation->registered_by_id)->toBeNull()
        ->and($donation->confirmed_by_id)->toBeNull()
        ->and($donation->manual_payment_method)->toBeNull()
        ->and($donation->donor_id)->toBe($payment->donor_id);
});

it('no crea donativo mientras el pago está pendiente, en proceso, rechazado o cancelado', function (FakeScenario $scenario, PaymentStatus $expected): void {
    $payment = startFakeDonation([], $scenario);

    expect($payment->status)->toBe($expected)
        ->and(Donation::query()->count())->toBe(0);
})->with([
    'pendiente' => [FakeScenario::Pending, PaymentStatus::Pending],
    'en proceso' => [FakeScenario::Processing, PaymentStatus::Processing],
    'rechazo corregible' => [FakeScenario::Declined, PaymentStatus::Pending],
    'fallo definitivo' => [FakeScenario::Failed, PaymentStatus::Failed],
]);

it('registra cada intento; un rechazo seguido de éxito queda como 2 intentos y 1 donativo', function (): void {
    $payment = startFakeDonation([], FakeScenario::InsufficientFunds);
    expect($payment->status)->toBe(PaymentStatus::Pending);

    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id)->assertOk();

    /** @var list<PaymentAttempt> $attempts */
    $attempts = PaymentAttempt::query()->where('payment_id', $payment->id)->orderBy('attempt_number')->get()->all();
    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($attempts[0]->failure_category)->toBe(FailureCategory::InsufficientFunds)
        ->and($attempts[0]->provider_code)->toBe('insufficient_funds')
        ->and($attempts[0]->initiated_by)->toBe(AttemptInitiator::Donor)
        ->and($attempts[0]->card_last4)->toBe('4242')
        ->and($attempts[1]->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and(Donation::query()->count())->toBe(1);
});

it('no abre incidencia por rechazos que el donante está corrigiendo', function (): void {
    $payment = startFakeDonation([], FakeScenario::ExpiredCard);
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Declined);
    deliverFakeWebhook('payment.failed', 'payment', (string) $payment->external_id);

    expect(PaymentAttempt::query()->count())->toBe(2)
        ->and(PaymentIncident::query()->count())->toBe(0);
});

it('un pago único que termina fallido por un motivo corregible por el donante no alerta', function (): void {
    $payment = startFakeDonation([], FakeScenario::Declined);
    fakeGateway()->setPaymentStatus((string) $payment->external_id, PaymentStatus::Failed);
    deliverFakeWebhook('payment.failed', 'payment', (string) $payment->external_id);

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Failed)
        ->and(PaymentIncident::query()->count())->toBe(0);
});

it('un pago único que termina fallido con algo que revisar abre una incidencia', function (): void {
    $payment = startFakeDonation([], FakeScenario::Failed);

    $incident = PaymentIncident::query()->sole();
    expect($incident->type)->toBe(IncidentType::OneTimePaymentFailed)
        ->and($incident->payment_id)->toBe($payment->id)
        ->and($incident->failure_category)->toBe(FailureCategory::ProcessingError);
});

it('el doble clic con la misma llave crea un solo pago y un solo cobro', function (): void {
    $donor = Donor::factory()->create();
    $input = ['provider' => 'fake', 'donor_id' => $donor->id, 'amount' => '200', 'idempotency_key' => 'form-'.Str::uuid()];

    $first = app(StartOneTimeDonation::class)->handle($input)['payment'];
    $second = app(StartOneTimeDonation::class)->handle($input)['payment'];

    expect($second->id)->toBe($first->id)
        ->and(Payment::query()->count())->toBe(1)
        ->and(Donation::query()->count())->toBe(1)
        ->and(PaymentAttempt::query()->count())->toBe(1);
});

it('rechaza reutilizar la llave de idempotencia con otros datos', function (): void {
    $donor = Donor::factory()->create();
    $key = 'form-'.Str::uuid();
    app(StartOneTimeDonation::class)->handle(['provider' => 'fake', 'donor_id' => $donor->id, 'amount' => '200', 'idempotency_key' => $key]);

    expect(fn () => app(StartOneTimeDonation::class)->handle(['provider' => 'fake', 'donor_id' => $donor->id, 'amount' => '999', 'idempotency_key' => $key]))
        ->toThrow(ValidationException::class);
    expect(Payment::query()->count())->toBe(1);
});

it('si el proveedor no está disponible, el pago queda pendiente y sin donativo', function (): void {
    expect(fn () => startFakeDonation([], FakeScenario::ProviderUnavailable))->toThrow(ProviderUnavailableException::class);

    $payment = Payment::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->external_id)->toBeNull()
        ->and(Donation::query()->count())->toBe(0);
});

it('timeout después de que el proveedor cobró: la notificación concilia por la referencia del CRM', function (): void {
    expect(fn () => startFakeDonation([], FakeScenario::TimeoutAfterProcessing))->toThrow(ProviderUnavailableException::class);

    $payment = Payment::query()->sole();
    expect($payment->external_id)->toBeNull()->and(Donation::query()->count())->toBe(0);

    $externalId = (string) fakeGateway()->paymentIdFor($payment->id);
    deliverFakeWebhook('payment.succeeded', 'payment', $externalId)->assertOk();

    expect($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->fresh()?->external_id)->toBe($externalId)
        ->and(Donation::query()->count())->toBe(1);
});

it('reintentar con la misma llave tras un timeout devuelve el mismo cobro del proveedor', function (): void {
    $donor = Donor::factory()->create();
    $input = ['provider' => 'fake', 'donor_id' => $donor->id, 'amount' => '350', 'idempotency_key' => 'form-'.Str::uuid()];

    fakeGateway()->willReturn(FakeScenario::TimeoutAfterProcessing);
    expect(fn () => app(StartOneTimeDonation::class)->handle($input))->toThrow(ProviderUnavailableException::class);

    $payment = app(StartOneTimeDonation::class)->handle($input)['payment'];

    expect(Payment::query()->count())->toBe(1)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and(Donation::query()->count())->toBe(1)
        ->and(PaymentAttempt::query()->count())->toBe(1);
});

it('valida donante, destino único e importe', function (array $input): void {
    expect(fn () => startFakeDonation($input))->toThrow(ValidationException::class);
    expect(Payment::query()->count())->toBe(0);
})->with([
    'importe con tres decimales' => [['amount' => '10.555']],
    'importe cero' => [['amount' => '0']],
    'donante archivado' => [fn () => ['donor_id' => Donor::factory()->create(['archived_at' => now()])->id]],
    'campaña y programa a la vez' => [fn () => ['campaign_id' => Campaign::factory()->create()->id, 'program_id' => Program::factory()->create()->id]],
    'llave demasiado corta' => [['idempotency_key' => 'corta']],
]);

it('no acepta un proveedor deshabilitado ni desconocido', function (string $provider): void {
    expect(fn () => startFakeDonation(['provider' => $provider]))->toThrow(ValidationException::class);
})->with(['stripe', 'mercado_pago', 'paypal']);

it('guarda el proveedor para siempre en el pago y el donativo en línea apunta a él', function (): void {
    $payment = startFakeDonation();

    expect($payment->provider)->toBe(PaymentProvider::Fake)
        ->and(Donation::query()->sole()->payment?->is($payment))->toBeTrue();
});
