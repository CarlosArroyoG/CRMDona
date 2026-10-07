<?php

declare(strict_types=1);

use App\Actions\Activities\CreateActivity;
use App\Actions\Donations\ConfirmDonation;
use App\Actions\DonorAssignments\AssignDonorResponsible;
use App\Actions\ExternalCfdi\AttachExternalCfdi;
use App\Actions\Tasks\CreateTask;
use App\DonorRelations\Timeline;
use App\Enums\ActivityType;
use App\Enums\ManualPaymentMethod;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Filament\Resources\AccountingControl\Pages\ListAccountingControl;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Communications\Pages\ListCommunications;
use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Widgets\DonorRelationsWork;
use App\Filament\Widgets\FundraisingOverview;
use App\Filament\Widgets\MyPortfolio;
use App\Filament\Widgets\UpcomingBirthdays;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Control de consultas por fila (N+1) en las pantallas principales: el número
 * de consultas no debe crecer con el número de registros mostrados.
 */

beforeEach(function (): void {
    Mail::fake();
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15',
    ])->save();
    actingAs(userWithRole(Role::Administrator));
});

function queriesFor(Closure $render): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $render();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * @param  Closure(int): mixed  $seed
 */
function assertNoPerRowQueries(Closure $seed, Closure $render, int $tolerance = 2): void
{
    $seed(3);
    $few = queriesFor($render);
    $seed(9);
    $many = queriesFor($render);

    expect($many)->toBeLessThanOrEqual($few + $tolerance, "Consultas con 3 filas: {$few}; con 12: {$many}.");
}

it('listados sin consultas por fila', function (string $page, Closure $seed): void {
    assertNoPerRowQueries($seed, fn () => Livewire::test($page));
})->with([
    'donantes' => [ListDonors::class, fn (int $n) => Donor::factory()->withTaxProfile()->count($n)->create()],
    'donativos' => [ListDonations::class, fn (int $n) => Donation::factory()->confirmed()->count($n)->create(['campaign_id' => Campaign::factory()->create()->id])],
    'pagos' => [ListPayments::class, fn (int $n) => Payment::factory()->succeeded()->count($n)->create()],
    'donativos mensuales' => [ListSubscriptions::class, fn (int $n) => Subscription::factory()->count($n)->create()],
    'historial de envíos' => [ListCommunications::class, fn (int $n) => Donation::factory()->count($n)->create()
        ->each(fn (Donation $donation) => app(ConfirmDonation::class)->handle($donation, userWithRole(Role::Accountant)))],
    'actividades' => [ListActivities::class, fn (int $n) => collect(range(1, $n))->each(fn () => app(CreateActivity::class)->handle([
        'donor_id' => Donor::factory()->create()->id, 'assigned_to_id' => userWithRole(Role::FundraisingCoordinator)->id,
        'type' => ActivityType::Call->value, 'subject' => 'Llamada', 'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toDateTimeString(),
    ], userWithRole(Role::FundraisingCoordinator)))],
    'tareas' => [ListTasks::class, fn (int $n) => collect(range(1, $n))->each(fn () => app(CreateTask::class)->handle([
        'assigned_to_id' => userWithRole(Role::FundraisingCoordinator)->id, 'title' => 'Tarea', 'priority' => 'medium',
    ], userWithRole(Role::FundraisingCoordinator)))],
]);

it('el timeline del donante no crece en consultas con más donativos, pagos y comunicaciones', function (): void {
    $donor = Donor::factory()->create();
    $admin = userWithRole(Role::Administrator);

    assertNoPerRowQueries(
        function (int $n) use ($donor): void {
            Donation::factory()->count($n)->create(['donor_id' => $donor->id]);
            Payment::factory()->succeeded()->count($n)->create(['donor_id' => $donor->id]);
            Subscription::factory()->count($n)->create(['donor_id' => $donor->id]);
        },
        fn () => Timeline::for($donor, $admin),
    );
});

it('el trabajo de relación con donantes y la cartera asignada no crecen en consultas', function (): void {
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    actingAs($coordinator);

    assertNoPerRowQueries(
        function (int $n) use ($coordinator): void {
            collect(range(1, $n))->each(function () use ($coordinator): void {
                $donor = Donor::factory()->create();
                app(AssignDonorResponsible::class)->handle($donor, $coordinator->id, null, $coordinator);
                app(CreateTask::class)->handle([
                    'donor_id' => $donor->id, 'assigned_to_id' => $coordinator->id, 'title' => 'Tarea',
                    'priority' => 'medium', 'due_date' => now()->addDay()->toDateString(),
                ], $coordinator);
            });
        },
        function (): void {
            Livewire::test(DonorRelationsWork::class);
            Livewire::test(MyPortfolio::class);
        },
        tolerance: 0,
    );
});

it('el tablero hace un número fijo de consultas', function (): void {
    assertNoPerRowQueries(
        fn (int $n) => Donation::factory()->confirmed()->count($n)->create(['received_on' => now()->toDateString()]),
        function (): void {
            Livewire::test(FundraisingOverview::class);
            Livewire::test(UpcomingBirthdays::class);
        },
        tolerance: 0,
    );
});

it('el control contable precarga recibo, aviso, CFDI externo y destino', function (): void {
    $seed = function (int $n): void {
        foreach (range(1, $n) as $i) {
            $donation = app(ConfirmDonation::class)->handle(
                Donation::factory()->create(['donor_id' => Donor::factory()->withTaxProfile()->create()->id, 'manual_payment_method' => ManualPaymentMethod::Cash, 'campaign_id' => Campaign::factory()->create()->id]),
                userWithRole(Role::Accountant),
            );
            app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml(), null, null, userWithRole(Role::Accountant));
        }
    };

    assertNoPerRowQueries($seed, fn () => Livewire::test(ListAccountingControl::class));
});
