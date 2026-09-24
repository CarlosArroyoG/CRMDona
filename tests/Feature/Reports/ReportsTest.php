<?php

declare(strict_types=1);

use App\Actions\Accounting\RetryAccountingNotice;
use App\Actions\Accounting\SetAccountingProcessed;
use App\Actions\Donations\ConfirmDonation;
use App\Actions\ExternalCfdi\AttachExternalCfdi;
use App\Actions\Incidents\OpenPaymentIncident;
use App\Enums\AccountingNoticeStatus;
use App\Enums\DonationStatus;
use App\Enums\IncidentType;
use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\AccountingControl\Pages\ListAccountingControl;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Widgets\FundraisingOverview;
use App\Filament\Widgets\UpcomingBirthdays;
use App\Models\AccountingNotice;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Export;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\Subscription;
use App\Reports\AccountingControl;
use App\Reports\DashboardMetrics;
use App\Reports\PaymentReport;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
    confirmedDonation('700.00', '2026-09-10', ['kind' => 'in_kind', 'manual_payment_method' => null, 'in_kind_description' => 'Útiles', 'in_kind_quantity' => '1.000', 'in_kind_unit_code' => 'H87', 'in_kind_product_service_code' => '49101700', 'in_kind_unit_value' => '700.00', 'in_kind_total_value' => '700.00']);
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

it('control contable: recibo, CFDI solicitado, aviso, CFDI externo y procesamiento, con filtros y exportación sin datos fiscales', function (): void {
    OrganizationSetting::current()->forceFill(['legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA'])->save();
    $accountant = userWithRole(Role::Accountant);
    actingAs($accountant);
    $confirm = fn (array $extra = []): Donation => app(ConfirmDonation::class)->handle(Donation::factory()->create([
        'donor_id' => Donor::factory()->withTaxProfile()->create()->id, 'manual_payment_method' => ManualPaymentMethod::Cash,
        'amount' => '1500.00', 'received_on' => '2026-09-05', ...$extra,
    ]), $accountant);

    $requested = $confirm(['tax_receipt_requested' => true]);
    $notRequested = $confirm();
    $withCfdi = $confirm(['tax_receipt_requested' => true]);
    $uuid = '11111111-2222-4333-8444-555555555555';
    app(AttachExternalCfdi::class)->handle($withCfdi, externalCfdiXml($uuid), null, null, $accountant);
    $processed = $confirm();
    app(SetAccountingProcessed::class)->handle($processed, true, 'Incluido en la factura global de septiembre.', $accountant);
    $old = $confirm(['received_on' => '2026-01-10']);
    $pending = Donation::factory()->create(['manual_payment_method' => ManualPaymentMethod::Cash]);

    Livewire::test(ListAccountingControl::class)
        ->assertCanSeeTableRecords([$requested, $notRequested, $withCfdi, $processed])
        ->assertCanNotSeeTableRecords([$pending])
        ->assertSee($uuid)->assertSee((string) $requested->receipt?->folio);
    Livewire::test(ListAccountingControl::class)->filterTable('tax_receipt_requested', true)
        ->assertCanSeeTableRecords([$requested, $withCfdi])->assertCanNotSeeTableRecords([$notRequested, $processed]);
    Livewire::test(ListAccountingControl::class)->filterTable('tax_receipt_requested', false)
        ->assertCanSeeTableRecords([$notRequested, $processed])->assertCanNotSeeTableRecords([$requested, $withCfdi]);
    Livewire::test(ListAccountingControl::class)->filterTable('external_cfdi', true)
        ->assertCanSeeTableRecords([$withCfdi])->assertCanNotSeeTableRecords([$requested, $notRequested]);
    Livewire::test(ListAccountingControl::class)->filterTable('processing', AccountingControl::PENDING)
        ->assertCanSeeTableRecords([$requested, $notRequested, $withCfdi])->assertCanNotSeeTableRecords([$processed]);
    Livewire::test(ListAccountingControl::class)->filterTable('processing', AccountingControl::PROCESSED)
        ->assertCanSeeTableRecords([$processed])->assertCanNotSeeTableRecords([$requested]);
    Livewire::test(ListAccountingControl::class)->filterTable('received_on', ['from' => '2026-09-01', 'until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$requested])->assertCanNotSeeTableRecords([$old]);

    Livewire::test(ListAccountingControl::class)->filterTable('external_cfdi', true)->callAction(TestAction::make('export')->table());
    $csv = exportedCsv(Export::query()->where('user_id', $accountant->id)->sole());
    expect($csv)->toContain($uuid)->toContain((string) $withCfdi->receipt?->folio)
        ->and(str_contains($csv, (string) $withCfdi->donor->taxProfile?->rfc))->toBeFalse();
});

it('control contable: marcar procesado (también en lote), reabrir con motivo y reenviar el aviso, con bitácora', function (): void {
    $accountant = userWithRole(Role::Accountant);
    actingAs($accountant);
    $donation = app(ConfirmDonation::class)->handle(Donation::factory()->create(['manual_payment_method' => ManualPaymentMethod::Cash]), $accountant);
    $others = Donation::factory()->count(2)->create(['manual_payment_method' => ManualPaymentMethod::Cash])
        ->map(fn (Donation $other): Donation => app(ConfirmDonation::class)->handle($other, $accountant));

    Livewire::test(ListAccountingControl::class)
        ->callAction(TestAction::make('markProcessed')->table($donation), ['note' => 'CFDI emitido fuera del CRM.']);
    $notice = $donation->accountingNotice()->sole();
    expect($notice->processed_at)->not->toBeNull()->and($notice->processed_by_id)->toBe($accountant->id)
        ->and($notice->processing_note)->toBe('CFDI emitido fuera del CRM.')
        ->and(AuditLog::query()->where('auditable_type', 'accounting_notice')->where('auditable_id', $notice->id)->where('event', 'updated')->exists())->toBeTrue();

    Livewire::test(ListAccountingControl::class)->callAction(TestAction::make('reopen')->table($donation), ['note' => 'Se procesó el donativo equivocado.']);
    expect($notice->refresh()->processed_at)->toBeNull()->and($notice->processed_by_id)->toBeNull();
    expect(fn () => app(SetAccountingProcessed::class)->handle($donation, false, 'Otra vez', $accountant))->toThrow(ValidationException::class);

    Livewire::test(ListAccountingControl::class)->callTableBulkAction('bulkMarkProcessed', $others->all());
    expect(AccountingNotice::query()->whereIn('donation_id', $others->pluck('id'))->whereNotNull('processed_at')->count())->toBe(2);

    // Sin destinatarios configurados el aviso quedó "No enviado"; al configurarlos se reenvía.
    expect($notice->status)->toBe(AccountingNoticeStatus::Skipped);
    $accountant->forceFill(['receives_accounting_notices' => true])->save();
    Livewire::test(ListAccountingControl::class)->callAction(TestAction::make('retryNotice')->table($donation));
    expect($notice->refresh()->status)->toBe(AccountingNoticeStatus::Sent)->and($notice->delivered_to)->toBe([$accountant->id]);
    Livewire::test(ListAccountingControl::class)->assertActionHidden(TestAction::make('retryNotice')->table($donation->refresh()));
});

it('permisos del control contable: Solo lectura no entra; el Coordinador consulta sin marcar ni reenviar', function (): void {
    $donation = app(ConfirmDonation::class)->handle(Donation::factory()->create(['manual_payment_method' => ManualPaymentMethod::Cash]), userWithRole(Role::Accountant));

    actingAs(userWithRole(Role::ReadOnly));
    get('/admin/control-contable')->assertForbidden();
    Livewire::test(ListPayments::class)->assertActionHidden(TestAction::make('export')->table())
        ->assertTableFilterHidden('failure_category')->assertTableFilterHidden('has_incident');
    expect(fn () => app(SetAccountingProcessed::class)->handle($donation, true, null, userWithRole(Role::ReadOnly)))->toThrow(AuthorizationException::class);

    actingAs(userWithRole(Role::FundraisingCoordinator));
    get('/admin/control-contable')->assertOk();
    Livewire::test(ListAccountingControl::class)->assertActionVisible(TestAction::make('export')->table())
        ->assertActionHidden(TestAction::make('markProcessed')->table($donation))
        ->assertActionHidden(TestAction::make('retryNotice')->table($donation));
    expect(fn () => app(RetryAccountingNotice::class)->handle($donation, userWithRole(Role::FundraisingCoordinator)))->toThrow(AuthorizationException::class);

    actingAs(userWithRole(Role::Accountant));
    get('/admin/control-contable')->assertOk();
    get('/admin/reporte-cfdi')->assertNotFound();
});
