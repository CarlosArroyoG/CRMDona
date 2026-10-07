<?php

declare(strict_types=1);

use App\Actions\Activities\CreateActivity;
use App\Actions\Donations\ConfirmDonation;
use App\Actions\DonorAssignments\AssignDonorResponsible;
use App\Actions\Tasks\CreateTask;
use App\DonorRelations\Timeline;
use App\Enums\ActivityType;
use App\Enums\AttemptInitiator;
use App\Enums\FailureCategory;
use App\Enums\PaymentAttemptStatus;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15',
    ])->save();
});

it('reúne eventos de varias fuentes reales, más recientes primero', function (): void {
    $donor = Donor::factory()->create();
    $admin = userWithRole(Role::Administrator);
    $coordinator = userWithRole(Role::FundraisingCoordinator);

    app(CreateActivity::class)->handle([
        'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'type' => ActivityType::Call->value,
        'subject' => 'Llamada', 'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toDateTimeString(),
    ], $coordinator);
    app(CreateTask::class)->handle(['donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'title' => 'Enviar propuesta', 'priority' => 'medium'], $coordinator);
    app(AssignDonorResponsible::class)->handle($donor, $coordinator->id, null, $admin);

    $donation = Donation::factory()->create(['donor_id' => $donor->id]);
    app(ConfirmDonation::class)->handle($donation, $admin);

    Payment::factory()->succeeded()->create(['donor_id' => $donor->id]);
    Subscription::factory()->create(['donor_id' => $donor->id]);

    $entries = Timeline::for($donor, $admin);
    $sources = $entries->pluck('source')->all();

    expect($sources)->toContain('Actividad', 'Tarea', 'Responsable', 'Donativo', 'Pago', 'Donativo mensual', 'Comunicación', 'Recibo', 'Aviso a Contabilidad')
        ->and($entries->pluck('occurredAt')->sortDesc()->values()->all())->toEqual($entries->pluck('occurredAt')->all());
});

it('cada entrada respeta el permiso que ya protege su propia fuente', function (): void {
    $donor = Donor::factory()->create();
    $payment = Payment::factory()->succeeded()->create(['donor_id' => $donor->id]);
    PaymentAttempt::query()->create([
        'payment_id' => $payment->id, 'provider' => 'fake', 'attempt_number' => 1,
        'status' => PaymentAttemptStatus::Failed->value, 'initiated_by' => AttemptInitiator::Donor->value,
        'failure_category' => FailureCategory::Declined->value, 'provider_message' => 'Tarjeta rechazada',
    ]);

    $administrator = userWithRole(Role::Administrator);
    $coordinator = userWithRole(Role::FundraisingCoordinator);

    expect(Timeline::for($donor, $administrator)->pluck('source')->all())->toContain('Intento fallido')
        ->and(Timeline::for($donor, $coordinator)->pluck('source')->all())->not->toContain('Intento fallido');
});

it('no incluye la bitácora genérica del donante: eso vive en su propia pantalla', function (): void {
    $donor = Donor::factory()->create();
    $admin = userWithRole(Role::Administrator);

    $sources = Timeline::for($donor, $admin)->pluck('source')->unique()->all();

    expect($sources)->not->toContain('Bitácora');
});
