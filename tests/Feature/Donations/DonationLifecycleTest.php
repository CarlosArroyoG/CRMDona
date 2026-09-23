<?php

declare(strict_types=1);

use App\Actions\Donations\CancelDonation;
use App\Actions\Donations\ConfirmDonation;
use App\Actions\Donations\RegisterDonation;
use App\Actions\Donations\UpdatePendingDonation;
use App\Enums\AuditEvent;
use App\Enums\DonationKind;
use App\Enums\DonationStatus;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Program;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function donationInput(array $overrides = []): array
{
    return [
        'donor_id' => Donor::factory()->create()->id,
        'kind' => 'monetary',
        'manual_payment_method' => 'cash',
        'amount' => '1500.50',
        'received_on' => now()->toDateString(),
        ...$overrides,
    ];
}

/**
 * @return array<string, array<int, string>>
 */
function donationErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Se esperaba un error de validación.');
}

it('registra todo donativo manual como "Por confirmar", incluso en efectivo', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);

    $donation = app(RegisterDonation::class)->handle(donationInput(), $actor);

    expect($donation->status)->toBe(DonationStatus::Pending)
        ->and($donation->fresh()?->amount)->toBe('1500.50')
        ->and($donation->currency)->toBe('MXN')
        ->and($donation->registered_by_id)->toBe($actor->id);
});

it('guarda el importe exacto como numeric sin pasar por float', function (): void {
    $donation = app(RegisterDonation::class)->handle(donationInput(['amount' => '9999999999.99']), userWithRole(Role::Accountant));

    expect(Donation::query()->whereKey($donation->id)->value('amount'))->toBe('9999999999.99');
});

it('rechaza importes inválidos', function (string $amount): void {
    expect(donationErrors(fn () => app(RegisterDonation::class)->handle(donationInput(['amount' => $amount]), userWithRole(Role::Administrator))))
        ->toHaveKey('amount');
})->with(['0', '-10', '10.555', '10000000000', 'mil']);

it('registra donativos en especie con descripción y valor asignado, sin forma de pago', function (): void {
    $donation = app(RegisterDonation::class)->handle(donationInput([
        'kind' => 'in_kind', 'manual_payment_method' => 'cash', 'amount' => '3200', 'in_kind_description' => 'Despensas para comedor',
    ]), userWithRole(Role::Administrator));

    expect($donation->kind)->toBe(DonationKind::InKind)
        ->and($donation->manual_payment_method)->toBeNull()
        ->and($donation->in_kind_description)->toBe('Despensas para comedor');

    expect(donationErrors(fn () => app(RegisterDonation::class)->handle(donationInput(['kind' => 'in_kind']), userWithRole(Role::Administrator))))
        ->toHaveKey('in_kind_description');
});

it('exige forma de pago y acepta los cuatro métodos manuales', function (string $method): void {
    expect(app(RegisterDonation::class)->handle(donationInput(['manual_payment_method' => $method]), userWithRole(Role::Administrator))->manual_payment_method?->value)
        ->toBe($method);
})->with(['cash', 'bank_transfer', 'check', 'bank_deposit']);

it('permite un solo destino: campaña o programa', function (): void {
    $errors = donationErrors(fn () => app(RegisterDonation::class)->handle(donationInput([
        'campaign_id' => Campaign::factory()->create()->id,
        'program_id' => Program::factory()->create()->id,
    ]), userWithRole(Role::Administrator)));

    expect($errors)->toHaveKey('campaign_id');
    expect(fn () => Donation::factory()->create([
        'campaign_id' => Campaign::factory()->create()->id,
        'program_id' => Program::factory()->create()->id,
    ]))->toThrow(QueryException::class);
});

it('reporta el programa efectivo directo o a través de la campaña', function (): void {
    $becas = Program::factory()->create(['name' => 'Becas']);
    $viaCampaign = Donation::factory()->create(['campaign_id' => Campaign::factory()->create(['program_id' => $becas->id])->id]);
    $direct = Donation::factory()->create(['program_id' => $becas->id]);
    Donation::factory()->create();

    expect($viaCampaign->effectiveProgram()?->id)->toBe($becas->id)
        ->and(Donation::query()->forProgram($becas->id)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$viaCampaign->id, $direct->id])->sort()->values()->all());
});

it('no registra donativos a donantes archivados', function (): void {
    $donor = Donor::factory()->archived()->create();

    expect(donationErrors(fn () => app(RegisterDonation::class)->handle(donationInput(['donor_id' => $donor->id]), userWithRole(Role::Administrator))))
        ->toHaveKey('donor_id');
});

it('confirma un pendiente dejando evidencia de quién y cuándo', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $donation = Donation::factory()->create();

    app(ConfirmDonation::class)->handle($donation, $accountant);

    $fresh = $donation->fresh();
    expect($fresh?->status)->toBe(DonationStatus::Confirmed)
        ->and($fresh?->confirmed_by_id)->toBe($accountant->id)
        ->and($fresh?->confirmed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', AuditEvent::Confirmed->value)->count())->toBe(1)
        ->and(AuditLog::query()->where('event', AuditEvent::Updated->value)->where('auditable_type', 'donation')->count())->toBe(0);
});

it('no confirma dos veces', function (): void {
    $donation = Donation::factory()->confirmed()->create();

    expect(donationErrors(fn () => app(ConfirmDonation::class)->handle($donation, userWithRole(Role::Accountant)))['donation'][0])
        ->toBe(ConfirmDonation::NOT_PENDING);
});

it('no modifica los datos de un donativo confirmado', function (): void {
    $donation = Donation::factory()->confirmed()->create();

    expect(donationErrors(fn () => app(UpdatePendingDonation::class)->handle($donation, donationInput(['donor_id' => $donation->donor_id])))['donation'][0])
        ->toBe(UpdatePendingDonation::NOT_PENDING)
        ->and($donation->fresh()?->amount)->toBe($donation->amount);
});

it('edita un donativo pendiente', function (): void {
    $donation = Donation::factory()->create();

    app(UpdatePendingDonation::class)->handle($donation, donationInput(['donor_id' => $donation->donor_id, 'amount' => '999.90']));

    expect($donation->fresh()?->amount)->toBe('999.90');
});

it('cancela pendientes y confirmados con motivo, y nunca dos veces', function (): void {
    $actor = userWithRole(Role::Accountant);
    $confirmed = Donation::factory()->confirmed()->create();

    app(CancelDonation::class)->handle($confirmed, 'Importe capturado con error', $actor);

    $fresh = $confirmed->fresh();
    expect($fresh?->status)->toBe(DonationStatus::Cancelled)
        ->and($fresh?->confirmed_at)->not->toBeNull()
        ->and($fresh?->cancelled_by_id)->toBe($actor->id)
        ->and($fresh?->cancellation_reason)->toBe('Importe capturado con error');

    expect(donationErrors(fn () => app(CancelDonation::class)->handle($confirmed, 'Otra vez', $actor))['donation'][0])
        ->toBe(CancelDonation::ALREADY_CANCELLED)
        ->and(donationErrors(fn () => app(CancelDonation::class)->handle(Donation::factory()->create(), '', $actor)))
        ->toHaveKey('cancellation_reason');
});

it('impide eliminar donativos desde la base de datos', function (): void {
    $donation = Donation::factory()->create();

    expect(fn () => $donation->delete())->toThrow(QueryException::class, 'Los donativos no se eliminan');
});

it('exige evidencia coherente con el estado en la base de datos', function (array $attributes): void {
    // Savepoint: el fallo esperado no debe abortar la transacción de la prueba.
    expect(fn () => DB::transaction(fn () => Donation::factory()->create($attributes)))
        ->toThrow(QueryException::class, 'donations_');
})->with([
    'confirmado sin evidencia' => [['status' => DonationStatus::Confirmed]],
    'cancelado sin motivo' => [['status' => DonationStatus::Cancelled, 'cancelled_at' => now()]],
    'moneda distinta de MXN' => [['currency' => 'USD']],
    'importe cero' => [['amount' => '0.00']],
    'especie sin descripción' => [['kind' => DonationKind::InKind, 'manual_payment_method' => null]],
]);
