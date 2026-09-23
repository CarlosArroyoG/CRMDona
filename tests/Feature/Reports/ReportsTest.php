<?php

declare(strict_types=1);

use App\Actions\Cfdi\BuildDonationCfdiDraft;
use App\Actions\Cfdi\RequestCfdiCancellation;
use App\Actions\Cfdi\RequestDonationCfdi;
use App\Actions\Incidents\OpenPaymentIncident;
use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Providers\FakeCfdiProvider;
use App\Enums\CfdiStatus;
use App\Enums\DonationStatus;
use App\Enums\FiscalRoute;
use App\Enums\IncidentType;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\TaxRegime;
use App\Filament\Resources\CfdiReports\Pages\ListCfdiReport;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Widgets\FundraisingOverview;
use App\Filament\Widgets\UpcomingBirthdays;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Export;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\Subscription;
use App\Reports\CfdiReport;
use App\Reports\DashboardMetrics;
use App\Reports\PaymentReport;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-09-15 12:00', 'America/Mexico_City'));
});

function metrics(): DashboardMetrics
{
    return app(DashboardMetrics::class);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function confirmedDonation(string $amount, string $receivedOn, array $attributes = []): Donation
{
    return Donation::factory()->confirmed()->create(['amount' => $amount, 'received_on' => $receivedOn, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function reportPayment(PaymentStatus $status, string $amount = '100.00', array $attributes = []): Payment
{
    $recurring = ($attributes['kind'] ?? null) === PaymentKind::RecurringCharge;
    $subscription = $recurring ? Subscription::factory()->create() : null;

    return Payment::factory()->create([
        'status' => $status, 'amount' => $amount,
        'succeeded_at' => $status === PaymentStatus::Succeeded ? now() : null,
        'subscription_id' => $subscription?->id, 'donor_id' => $subscription->donor_id ?? Donor::factory(),
        'billing_period_start' => $recurring ? now()->startOfMonth() : null,
        ...$attributes,
    ]);
}

function reportAttempt(Payment $payment, string $status, int $number): PaymentAttempt
{
    return PaymentAttempt::query()->create([
        'payment_id' => $payment->id, 'provider' => $payment->provider, 'external_id' => 'att_'.Str::random(8),
        'attempt_number' => $number, 'status' => $status, 'initiated_by' => 'provider',
        'failure_category' => $status === 'failed' ? 'insufficient_funds' : null,
    ]);
}

function reportRefund(Payment $payment, string $amount): Refund
{
    return Refund::query()->create([
        'payment_id' => $payment->id, 'provider' => $payment->provider, 'external_id' => 're_'.Str::random(8), 'amount' => $amount,
        'status' => 'succeeded', 'reason' => 'provider_initiated', 'source' => 'provider', 'requested_at' => now(),
        'processed_at' => now(), 'idempotency_key' => 'r-'.Str::uuid(),
    ]);
}

it('recaudado del mes: solo donativos confirmados en dinero por fecha de recepción, con precisión exacta', function (): void {
    confirmedDonation('0.10', '2026-09-01');
    confirmedDonation('0.20', '2026-09-30');
    confirmedDonation('1000.00', '2026-08-31');
    Donation::factory()->create(['amount' => '500.00', 'received_on' => '2026-09-10']); // por confirmar
    confirmedDonation('700.00', '2026-09-10', ['kind' => 'in_kind', 'manual_payment_method' => null, 'in_kind_description' => 'Útiles']);
    $cancelled = confirmedDonation('300.00', '2026-09-11');
    DB::table('donations')->where('id', $cancelled->id)->update(['status' => DonationStatus::Cancelled->value, 'cancelled_at' => now(), 'cancelled_by_id' => $cancelled->registered_by_id, 'cancellation_reason' => 'Error de captura']);

    expect(metrics()->raisedInMonth(CarbonImmutable::now()))->toBe('0.30')
        ->and(metrics()->raisedInMonth(CarbonImmutable::now()->subMonthNoOverflow()))->toBe('1000.00');
});

it('mes sin datos: todo en cero y sin base de comparación', function (): void {
    $month = CarbonImmutable::now();

    expect(metrics()->raisedInMonth($month))->toBe('0.00')
        ->and(metrics()->refundedInMonth($month))->toBe('0.00')
        ->and(metrics()->newDonorsInMonth($month))->toBe(0)
        ->and(metrics()->subscriptionCounts())->toBe(['active' => 0, 'past_due' => 0, 'paused' => 0])
        ->and(metrics()->paymentFailureRate($month))->toBe(['failed' => 0, 'finished' => 0, 'rate' => null])
        ->and(metrics()->changePercent('0.00', '0.00'))->toBeNull();
});

it('comparación contra el mes anterior con un decimal', function (string $current, string $previous, ?string $expected): void {
    /** @var numeric-string $current */
    /** @var numeric-string $previous */
    expect(metrics()->changePercent($current, $previous))->toBe($expected);
})->with([
    ['1500.00', '1000.00', '50.0'],
    ['500.00', '1000.00', '-50.0'],
    ['1000.00', '3000.00', '-66.7'],
    ['1.00', '3.00', '-66.7'],
    ['200.00', '300.00', '-33.3'],
    ['100.00', '0.00', null],
]);

it('donantes nuevos: primer donativo confirmado en el mes', function (): void {
    $new = Donor::factory()->create();
    confirmedDonation('100.00', '2026-09-02', ['donor_id' => $new->id]);
    confirmedDonation('100.00', '2026-09-20', ['donor_id' => $new->id]);
    $returning = Donor::factory()->create();
    confirmedDonation('100.00', '2026-05-02', ['donor_id' => $returning->id]);
    confirmedDonation('100.00', '2026-09-03', ['donor_id' => $returning->id]);
    Donation::factory()->create(['received_on' => '2026-09-04']); // por confirmar: no cuenta
    Donor::factory()->create(); // sin donativos: no cuenta

    expect(metrics()->newDonorsInMonth(CarbonImmutable::now()))->toBe(1);
});

it('donativos mensuales por estado actual', function (): void {
    Subscription::factory()->count(2)->create();
    Subscription::factory()->create(['status' => SubscriptionStatus::PastDue]);
    Subscription::factory()->create(['status' => SubscriptionStatus::Paused, 'paused_at' => now()]);
    Subscription::factory()->create(['status' => SubscriptionStatus::Pending]);

    expect(metrics()->subscriptionCounts())->toBe(['active' => 2, 'past_due' => 1, 'paused' => 1]);
});

it('tasa de fallos: fallidos entre terminados; el recuperado cuenta como exitoso; pendientes y cancelados no entran', function (): void {
    reportPayment(PaymentStatus::Succeeded);
    $recovered = reportPayment(PaymentStatus::Succeeded, attributes: ['kind' => PaymentKind::RecurringCharge]);
    reportAttempt($recovered, 'failed', 1);
    reportAttempt($recovered, 'succeeded', 2);
    reportPayment(PaymentStatus::Failed);
    reportPayment(PaymentStatus::Pending);
    reportPayment(PaymentStatus::Cancelled);
    reportPayment(PaymentStatus::Failed, attributes: ['created_at' => now()->subMonth()]);

    expect(metrics()->paymentFailureRate(CarbonImmutable::now()))->toBe(['failed' => 1, 'finished' => 3, 'rate' => '33.3']);
});

it('reembolsado del mes: solo reembolsos exitosos procesados en el mes', function (): void {
    reportRefund(reportPayment(PaymentStatus::Succeeded, '500.00'), '120.55');

    expect(metrics()->refundedInMonth(CarbonImmutable::now()))->toBe('120.55');
});

it('cumpleaños próximos: 7 días, sin archivados, ordenados', function (): void {
    $in3 = Donor::factory()->create(['birth_date' => '1990-09-18']);
    $today = Donor::factory()->create(['birth_date' => '1985-09-15']);
    Donor::factory()->archived()->create(['birth_date' => '1985-09-16']);
    Donor::factory()->create(['birth_date' => '1985-09-22']); // 7 días después: fuera

    expect(metrics()->upcomingBirthdays(CarbonImmutable::now())->pluck('id')->all())->toBe([$today->id, $in3->id]);
});

it('el tablero se muestra a los cuatro roles y el de cumpleaños no rompe', function (Role $role): void {
    confirmedDonation('1500.00', '2026-09-02');
    actingAs(userWithRole($role));

    get('/admin')->assertOk();
    Livewire::test(FundraisingOverview::class)->assertSee('Recaudado en')->assertSee('$1,500.00 MXN')->assertSee('Tasa de fallos');
    Livewire::test(UpcomingBirthdays::class)->assertSee('Cumpleaños próximos');
})->with([Role::Administrator, Role::FundraisingCoordinator, Role::Accountant, Role::ReadOnly]);

it('reporte de pagos: recuperados, cobros mensuales fallidos, reembolsos parcial/total (no son cancelados) e incidencias', function (): void {
    actingAs(userWithRole(Role::Accountant));
    $recovered = reportPayment(PaymentStatus::Succeeded, '300.00', ['kind' => PaymentKind::RecurringCharge]);
    reportAttempt($recovered, 'failed', 1);
    reportAttempt($recovered, 'succeeded', 2);
    $failedRecurring = reportPayment(PaymentStatus::Failed, '300.00', ['kind' => PaymentKind::RecurringCharge]);
    $failedOnce = reportPayment(PaymentStatus::Failed);
    $partial = reportPayment(PaymentStatus::Succeeded, '500.00');
    reportRefund($partial, '100.00');
    $full = reportPayment(PaymentStatus::Succeeded, '200.00');
    reportRefund($full, '200.00');
    $cancelled = reportPayment(PaymentStatus::Cancelled);
    app(OpenPaymentIncident::class)->handle(IncidentType::cases()[0], 'test:'.$failedOnce->id, payment: $failedOnce);

    Livewire::test(ListPayments::class)->filterTable('situation', PaymentReport::RECOVERED)
        ->assertCanSeeTableRecords([$recovered])->assertCanNotSeeTableRecords([$failedRecurring, $partial, $full]);
    Livewire::test(ListPayments::class)->filterTable('situation', PaymentReport::FAILED_RECURRING)
        ->assertCanSeeTableRecords([$failedRecurring])->assertCanNotSeeTableRecords([$failedOnce, $recovered]);
    Livewire::test(ListPayments::class)->filterTable('refund_state', 'partial')
        ->assertCanSeeTableRecords([$partial])->assertCanNotSeeTableRecords([$full, $cancelled]);
    Livewire::test(ListPayments::class)->filterTable('refund_state', 'full')
        ->assertCanSeeTableRecords([$full])->assertCanNotSeeTableRecords([$partial, $cancelled]);
    Livewire::test(ListPayments::class)->filterTable('status', PaymentStatus::Cancelled->value)
        ->assertCanSeeTableRecords([$cancelled])->assertCanNotSeeTableRecords([$full, $partial]);
    Livewire::test(ListPayments::class)->filterTable('has_incident', true)
        ->assertCanSeeTableRecords([$failedOnce])->assertCanNotSeeTableRecords([$failedRecurring]);
    Livewire::test(ListPayments::class)->filterTable('failure_category', 'insufficient_funds')
        ->assertCanSeeTableRecords([$recovered])->assertCanNotSeeTableRecords([$failedOnce]);
});

it('reporte de pagos: filtros combinados (mensual + exitoso + rango + importe + campaña)', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $campaign = Campaign::factory()->create();
    $match = reportPayment(PaymentStatus::Succeeded, '300.00', ['kind' => PaymentKind::RecurringCharge, 'campaign_id' => $campaign->id]);
    $otherCampaign = reportPayment(PaymentStatus::Succeeded, '300.00', ['kind' => PaymentKind::RecurringCharge]);
    $oneTime = reportPayment(PaymentStatus::Succeeded, '300.00', ['campaign_id' => $campaign->id]);
    $tooBig = reportPayment(PaymentStatus::Succeeded, '9000.00', ['kind' => PaymentKind::RecurringCharge, 'campaign_id' => $campaign->id]);
    $old = reportPayment(PaymentStatus::Succeeded, '300.00', ['kind' => PaymentKind::RecurringCharge, 'campaign_id' => $campaign->id, 'created_at' => '2026-01-10 10:00']);

    Livewire::test(ListPayments::class)
        ->filterTable('kind', PaymentKind::RecurringCharge->value)
        ->filterTable('status', PaymentStatus::Succeeded->value)
        ->filterTable('campaign_id', $campaign->id)
        ->filterTable('created_at', ['from' => '2026-09-01', 'until' => '2026-09-30'])
        ->filterTable('amount', ['min' => '100', 'max' => '1000'])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$otherCampaign, $oneTime, $tooBig, $old]);
});

it('totales del reporte de pagos en NUMERIC: importe, cobrado y reembolsado', function (): void {
    $a = reportPayment(PaymentStatus::Succeeded, '0.10');
    reportPayment(PaymentStatus::Succeeded, '0.20');
    reportPayment(PaymentStatus::Failed, '1000.01');
    reportRefund($a, '0.05');

    expect(PaymentReport::totals(Payment::query()))->toBe(['count' => 3, 'amount' => '1000.31', 'succeeded' => '0.30', 'refunded' => '0.05'])
        ->and(PaymentReport::totals(Payment::query()->where('status', 'failed')))->toBe(['count' => 1, 'amount' => '1000.01', 'succeeded' => '0.00', 'refunded' => '0.00']);
});

it('exporta el reporte de pagos con los filtros y la columna "Recuperado"', function (): void {
    $user = userWithRole(Role::Accountant);
    actingAs($user);
    $recovered = reportPayment(PaymentStatus::Succeeded, '333.33');
    reportAttempt($recovered, 'failed', 1);
    reportPayment(PaymentStatus::Failed, '444.44');

    Livewire::test(ListPayments::class)->filterTable('situation', PaymentReport::RECOVERED)->callAction(TestAction::make('export')->table());

    $csv = exportedCsv(Export::query()->where('user_id', $user->id)->sole());
    expect($csv)->toContain('333.33')->toContain('Recuperado tras rechazo')
        ->and(str_contains($csv, '444.44'))->toBeFalse();
});

it('el reporte de pagos evita consultas por fila (N+1)', function (): void {
    actingAs(userWithRole(Role::Administrator));
    foreach (range(1, 3) as $i) {
        reportRefund(reportPayment(PaymentStatus::Succeeded, '100.00'), '10.00');
    }
    DB::enableQueryLog();
    Livewire::test(ListPayments::class);
    $few = count(DB::getQueryLog());

    foreach (range(1, 6) as $i) {
        reportRefund(reportPayment(PaymentStatus::Succeeded, '100.00'), '10.00');
    }
    DB::flushQueryLog();
    Livewire::test(ListPayments::class);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($few + 2);
});

it('reporte CFDI: estado, UUID, ruta individual / público en general / bloqueado, cancelaciones y fechas', function (): void {
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15',
    ])->save();
    $admin = userWithRole(Role::Administrator);
    actingAs($admin);
    $withTax = fn (array $extra = []) => confirmedDonation('1500.00', '2026-09-05', ['donor_id' => Donor::factory()->withTaxProfile()->create()->id, 'manual_payment_method' => ManualPaymentMethod::Cash, ...$extra]);

    $stamped = $withTax();
    $cfdi = app(RequestDonationCfdi::class)->handle($stamped, $admin)->refresh();
    $cancelledOne = $withTax();
    $toCancel = app(RequestDonationCfdi::class)->handle($cancelledOne, $admin)->refresh();
    app(RequestCfdiCancellation::class)->handle($toCancel, '02', 'RFC equivocado', $admin);
    $public = confirmedDonation('200.00', '2026-09-06', ['manual_payment_method' => ManualPaymentMethod::Cash]);
    $blocked = $withTax(['manual_payment_method' => ManualPaymentMethod::BankDeposit]);
    $old = $withTax(['received_on' => '2026-01-10']);

    $report = app(CfdiReport::class);
    $load = fn (Donation $donation) => CfdiReport::query()->findOrFail($donation->id);
    expect(CfdiReport::activeCfdi($load($stamped))?->uuid)->toBe($cfdi->uuid)
        ->and($report->coverage($load($stamped))->route)->toBe(FiscalRoute::Individual)
        ->and($report->coverage($load($public))->route)->toBe(FiscalRoute::PublicGeneral)
        ->and($report->coverage($load($blocked))->route)->toBe(FiscalRoute::Blocked)
        ->and(CfdiReport::cancelledCount($load($cancelledOne)))->toBe(1)
        ->and(CfdiReport::activeCfdi($load($cancelledOne)))->toBeNull();

    Livewire::test(ListCfdiReport::class)
        ->assertCanSeeTableRecords([$stamped, $public, $blocked, $cancelledOne])
        ->assertSee((string) $cfdi->uuid)->assertSee('depósito bancario');
    Livewire::test(ListCfdiReport::class)->filterTable('cfdi_status', CfdiStatus::Stamped->value)
        ->assertCanSeeTableRecords([$stamped])->assertCanNotSeeTableRecords([$public, $blocked, $cancelledOne]);
    Livewire::test(ListCfdiReport::class)->filterTable('cfdi_status', CfdiReport::NO_CFDI)
        ->assertCanSeeTableRecords([$public, $blocked, $cancelledOne])->assertCanNotSeeTableRecords([$stamped]);
    Livewire::test(ListCfdiReport::class)->filterTable('public_general', true)
        ->assertCanSeeTableRecords([$public])->assertCanNotSeeTableRecords([$stamped, $blocked]);
    Livewire::test(ListCfdiReport::class)->filterTable('cancellations', true)
        ->assertCanSeeTableRecords([$cancelledOne])->assertCanNotSeeTableRecords([$stamped]);
    Livewire::test(ListCfdiReport::class)->filterTable('received_on', ['from' => '2026-09-01', 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$stamped])->assertCanNotSeeTableRecords([$old]);

    Livewire::test(ListCfdiReport::class)->filterTable('cfdi_status', CfdiStatus::Stamped->value)->callAction(TestAction::make('export')->table());
    $csv = exportedCsv(Export::query()->where('user_id', $admin->id)->sole());
    expect($csv)->toContain((string) $cfdi->uuid)->toContain('CFDI individual')
        ->and(str_contains($csv, (string) $stamped->donor->taxProfile?->rfc))->toBeFalse();
});

it('el filtro de público en general coincide con la regla de emisión', function (): void {
    $none = confirmedDonation('100.00', '2026-09-05');
    $generic = confirmedDonation('100.00', '2026-09-05', ['donor_id' => Donor::factory()->withTaxProfile()->create()->id]);
    $generic->donor->taxProfile?->forceFill(['rfc' => 'XAXX010101000'])->save();
    $withRfc = confirmedDonation('100.00', '2026-09-05', ['donor_id' => Donor::factory()->withTaxProfile()->create()->id]);

    $sql = CfdiReport::wherePublicGeneral(Donation::query(), true)->pluck('id')->sort()->values()->all();
    $rule = collect([$none, $generic, $withRfc])->filter(fn (Donation $donation): bool => app(BuildDonationCfdiDraft::class)->isPublicGeneral($donation->fresh() ?? $donation))
        ->pluck('id')->sort()->values()->all();

    expect($sql)->toBe($rule)->toBe([$none->id, $generic->id]);
});

it('permisos: Solo lectura no ve el reporte CFDI ni exporta pagos; el Coordinador no ve el error técnico', function (): void {
    actingAs(userWithRole(Role::ReadOnly));
    get('/admin/reporte-cfdi')->assertForbidden();
    Livewire::test(ListPayments::class)->assertActionHidden(TestAction::make('export')->table())
        ->assertTableFilterHidden('failure_category')->assertTableFilterHidden('has_incident');

    actingAs(userWithRole(Role::FundraisingCoordinator));
    get('/admin/reporte-cfdi')->assertOk();
    Livewire::test(ListCfdiReport::class)->assertActionVisible(TestAction::make('export')->table());

    actingAs(userWithRole(Role::Accountant));
    get('/admin/reporte-cfdi')->assertOk();
});

it('el Coordinador ve "Rechazado por datos" sin el mensaje técnico del PAC', function (): void {
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15',
    ])->save();
    $donation = confirmedDonation('100.00', '2026-09-05', ['donor_id' => Donor::factory()->withTaxProfile()->create()->id, 'manual_payment_method' => ManualPaymentMethod::Cash]);
    /** @var FakeCfdiProvider $pac */
    $pac = app(CfdiProviderRegistry::class)->current();
    $pac->willStamp(FakeCfdiProvider::STAMP_REJECTED);
    app(RequestDonationCfdi::class)->handle($donation, userWithRole(Role::Administrator));

    actingAs(userWithRole(Role::FundraisingCoordinator));
    Livewire::test(ListCfdiReport::class)->assertSee('detalle para Administrador y Contador')->assertDontSee('RFC del receptor no válido');
    actingAs(userWithRole(Role::Accountant));
    Livewire::test(ListCfdiReport::class)->assertSee('RFC del receptor no válido');
    expect(Storage::disk('local'))->not->toBeNull();
});
