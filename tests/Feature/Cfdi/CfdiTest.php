<?php

declare(strict_types=1);

use App\Actions\Cfdi\BuildDonationCfdiDraft;
use App\Actions\Cfdi\RequestCfdiCancellation;
use App\Actions\Cfdi\RequestDonationCfdi;
use App\Actions\Cfdi\RetryCfdi;
use App\Actions\Donations\ConfirmDonation;
use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Data\CancellationResult;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Cfdi\Providers\FakeCfdiProvider;
use App\Enums\AuditSource;
use App\Enums\CfdiStatus;
use App\Enums\DonationStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Jobs\ReconcileCfdis;
use App\Models\AuditLog;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Payments\Gateways\FakeScenario;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

beforeEach(function (): void {
    Storage::fake('local');
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15',
    ])->save();
});

function fakePac(): FakeCfdiProvider
{
    /** @var FakeCfdiProvider $provider */
    $provider = app(CfdiProviderRegistry::class)->current();

    return $provider;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function cfdiReadyDonation(array $attributes = [], bool $organization = false): Donation
{
    $donor = ($organization ? Donor::factory()->organization() : Donor::factory())->withTaxProfile()->create();

    return Donation::factory()->confirmed()->create([
        'donor_id' => $donor->id, 'manual_payment_method' => ManualPaymentMethod::Cash, 'amount' => '1500.00', ...$attributes,
    ]);
}

it('arma el CFDI con las reglas verificadas del SAT para un donativo en efectivo de persona física', function (): void {
    $draft = app(BuildDonationCfdiDraft::class)->handle(cfdiReadyDonation());

    expect([$draft->voucherType, $draft->paymentMethod, $draft->paymentForm, $draft->cfdiUse, $draft->productCode,
        $draft->unitCode, $draft->quantity, $draft->description, $draft->taxObject, $draft->currency, $draft->total])
        ->toBe(['I', 'PUE', '01', 'D04', '84101600', 'M4', '1', 'Donativo', '01', 'MXN', '1500.00'])
        ->and($draft->legend)->toBe(BuildDonationCfdiDraft::DONATARIA_LEGEND)
        ->and($draft->authorizationNumber)->toBe('600-04-02-2026-0001')
        ->and($draft->authorizationDate)->toBe('2026-01-15')
        ->and($draft->issuerRfc)->toBe('FPR010101AAA');
});

it('usa G03 para persona moral y S01 para RESICO; cheque 02 y transferencia 03', function (): void {
    expect(app(BuildDonationCfdiDraft::class)->handle(cfdiReadyDonation(organization: true))->cfdiUse)->toBe('G03');

    $resico = cfdiReadyDonation(['manual_payment_method' => ManualPaymentMethod::Check]);
    $resico->donor->taxProfile?->forceFill(['tax_regime' => TaxRegime::SimplifiedTrust])->save();
    $draft = app(BuildDonationCfdiDraft::class)->handle($resico->fresh() ?? $resico);
    expect($draft->cfdiUse)->toBe('S01')->and($draft->paymentForm)->toBe('02');

    expect(app(BuildDonationCfdiDraft::class)->handle(cfdiReadyDonation(['manual_payment_method' => ManualPaymentMethod::BankTransfer]))->paymentForm)->toBe('03');
});

it('no inventa reglas: bloquea con motivo lo pendiente de decisión fiscal o de datos', function (Closure $donation, string $reason): void {
    expect(fn () => app(BuildDonationCfdiDraft::class)->handle($donation()))->toThrow(CfdiNotReadyException::class, $reason);
})->with([
    'sin datos fiscales del donante (público en general)' => [fn () => Donation::factory()->confirmed()->create(), 'XAXX010101000'],
    'especie' => [fn () => cfdiReadyDonation(['kind' => 'in_kind', 'manual_payment_method' => null, 'in_kind_description' => 'Útiles']), 'especie'],
    'depósito bancario' => [fn () => cfdiReadyDonation(['manual_payment_method' => ManualPaymentMethod::BankDeposit]), 'depósito'],
    'donativo por confirmar' => [fn () => Donation::factory()->create(), 'confirmados'],
    'uso de CFDI incorrecto en el perfil' => [function () {
        $donation = cfdiReadyDonation();
        $donation->donor->taxProfile?->forceFill(['cfdi_use' => 'G03'])->save();

        return $donation->fresh();
    }, 'debe ser D04'],
    'donativo en línea con tarjeta' => [function () {
        startFakeDonation([], FakeScenario::Success);
        $donation = Donation::query()->where('origin', 'online')->sole();
        $donation->donor()->first()?->taxProfile()->create(['rfc' => 'AAA010101AAA', 'tax_name' => 'X', 'tax_regime' => TaxRegime::Wages, 'tax_postal_code' => '62000']);

        return $donation->fresh();
    }, 'crédito (04)'],
]);

it('bloquea si faltan datos de la organización', function (): void {
    OrganizationSetting::current()->forceFill(['authorization_number' => null])->save();

    expect(fn () => app(BuildDonationCfdiDraft::class)->handle(cfdiReadyDonation()))->toThrow(CfdiNotReadyException::class, 'número de oficio');
});

it('emite: queda timbrado con UUID, XML con complemento de donatarias y PDF en disco privado', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), $accountant)->refresh();

    expect($cfdi->status)->toBe(CfdiStatus::Stamped)
        ->and($cfdi->uuid)->not->toBeNull()
        ->and($cfdi->requested_by_id)->toBe($accountant->id)
        ->and($cfdi->folio)->toBe((string) $cfdi->id)
        ->and($cfdi->series)->toBe('DON')
        ->and($cfdi->xml_path)->toStartWith('cfdi/');

    Storage::disk('local')->assertExists([(string) $cfdi->xml_path, (string) $cfdi->pdf_path]);
    $xml = (string) Storage::disk('local')->get((string) $cfdi->xml_path);
    expect($xml)->toContain('donat:Donatarias')->toContain('UsoCFDI="D04"')->toContain('84101600')->toContain('sin sello ni validez fiscal');
});

it('es idempotente: un donativo tiene un solo CFDI vigente aunque se solicite dos veces', function (): void {
    $donation = cfdiReadyDonation();
    $admin = userWithRole(Role::Administrator);

    $first = app(RequestDonationCfdi::class)->handle($donation, $admin, 'k1');
    $second = app(RequestDonationCfdi::class)->handle($donation, $admin, 'k2');

    expect($second->id)->toBe($first->id)->and(Cfdi::query()->count())->toBe(1)->and(fakePac()->stampedCount())->toBe(1);
    expect(fn () => DB::transaction(fn () => Cfdi::query()->create([
        'donation_id' => $donation->id, 'provider' => 'fake', 'status' => 'pending', 'total' => '1', 'idempotency_key' => 'otra', 'requested_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('error temporal del PAC: queda con error y el reintento no duplica el timbrado', function (): void {
    fakePac()->willStamp(FakeCfdiProvider::STAMP_TIMEOUT_AFTER);
    $admin = userWithRole(Role::Administrator);
    config(['queue.default' => 'database']);

    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), $admin);
    config(['cfdi.stamping.tries' => 1]);
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);
    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Failed)->and($cfdi->last_error_code)->toBe('unavailable');

    config(['queue.default' => 'sync']);
    app(RetryCfdi::class)->handle($cfdi, $admin);

    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Stamped)->and(fakePac()->stampedCount())->toBe(1)->and($cfdi->attempts)->toBe(2);
});

it('rechazo por datos: queda "rechazado por datos" con el motivo y se puede reintentar', function (): void {
    fakePac()->willStamp(FakeCfdiProvider::STAMP_REJECTED);
    $admin = userWithRole(Role::Administrator);

    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), $admin)->refresh();
    expect($cfdi->status)->toBe(CfdiStatus::Rejected)->and($cfdi->last_error_code)->toBe('CFDI40145');

    expect(app(RetryCfdi::class)->handle($cfdi, $admin)->status)->toBe(CfdiStatus::Stamped);
});

it('un timbrado interrumpido se reencola con la misma llave en la conciliación', function (): void {
    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), userWithRole(Role::Administrator));
    DB::table('cfdis')->where('id', $cfdi->id)->update(['status' => 'stamping', 'updated_at' => now()->subHour(), 'uuid' => null, 'stamped_at' => null, 'xml_path' => null]);
    travel(1)->hours();

    dispatch_sync(new ReconcileCfdis);
    travel(1)->hours();
    dispatch_sync(new ReconcileCfdis);

    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Stamped);
});

it('cancela con motivo 02 y razón, sin tocar el donativo; queda auditado', function (): void {
    $admin = userWithRole(Role::Administrator);
    actingAs($admin);
    $cfdi = app(RequestDonationCfdi::class)->handle($donation = cfdiReadyDonation(), $admin)->refresh();

    app(RequestCfdiCancellation::class)->handle($cfdi, '02', 'Se capturó el RFC equivocado', $admin);

    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Cancelled)->and($cfdi->cancelled_at)->not->toBeNull()
        ->and($donation->fresh()?->status)->toBe(DonationStatus::Confirmed);
    $log = AuditLog::query()->where('auditable_type', 'cfdi')->where('event', 'cancelled')->sole();
    expect($log->user_id)->toBe($admin->id)->and($log->new_values)->toMatchArray(['reason' => 'Se capturó el RFC equivocado']);

    // Cancelado el anterior, se puede emitir uno nuevo para el mismo donativo.
    expect(app(RequestDonationCfdi::class)->handle($donation, $admin, 'nuevo')->refresh()->status)->toBe(CfdiStatus::Stamped);
});

it('cancelación en espera de aceptación del receptor y rechazo: el CFDI sigue vigente si se rechaza', function (): void {
    $admin = userWithRole(Role::Administrator);
    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), $admin)->refresh();
    fakePac()->willCancel(CancellationResult::PENDING_ACCEPTANCE, CancellationResult::REJECTED);

    app(RequestCfdiCancellation::class)->handle($cfdi, '03', 'Donativo devuelto al donante', $admin);
    expect($cfdi->refresh()->status)->toBe(CfdiStatus::CancellationPending);

    travel(2)->hours();
    dispatch_sync(new ReconcileCfdis);

    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Stamped)->and($cfdi->cancellation_provider_status)->toBe('fake:rejected');
});

it('solo cancela con 02 o 03 (01 y 04 requieren el flujo de sustitución pendiente) y exige razón', function (string $motive): void {
    $admin = userWithRole(Role::Administrator);
    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), $admin)->refresh();

    expect(fn () => app(RequestCfdiCancellation::class)->handle($cfdi, $motive, 'Razón suficiente', $admin))->toThrow(ValidationException::class);
})->with(['01', '04', '99']);

it('permisos: emitir y cancelar solo Administrador y Contador; Coordinador y Solo lectura no', function (Role $role, bool $allowed): void {
    $attempt = fn () => app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), userWithRole($role));

    $allowed ? expect($attempt()->refresh()->status)->toBe(CfdiStatus::Stamped) : expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    [Role::Administrator, true], [Role::Accountant, true], [Role::FundraisingCoordinator, false], [Role::ReadOnly, false],
]);

it('descarga XML y PDF solo con permiso y desde el disco privado', function (): void {
    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), userWithRole(Role::Administrator))->refresh();

    get(route('cfdi.files', [$cfdi, 'xml']))->assertRedirect();
    actingAs(userWithRole(Role::ReadOnly));
    get(route('cfdi.files', [$cfdi, 'xml']))->assertForbidden();
    actingAs(userWithRole(Role::FundraisingCoordinator));
    get(route('cfdi.files', [$cfdi, 'xml']))->assertOk()->assertHeader('Content-Type', 'application/xml');
    get(route('cfdi.files', [$cfdi, 'pdf']))->assertOk();
});

it('emisión automática apagada por defecto; encendida emite al confirmar si se solicitó recibo', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $pending = cfdiReadyDonation(['status' => DonationStatus::Pending, 'confirmed_at' => null, 'confirmed_by_id' => null, 'tax_receipt_requested' => true]);

    app(ConfirmDonation::class)->handle($pending, $accountant);
    expect(Cfdi::query()->count())->toBe(0);

    config(['cfdi.auto_issue' => true]);
    $other = cfdiReadyDonation(['status' => DonationStatus::Pending, 'confirmed_at' => null, 'confirmed_by_id' => null, 'tax_receipt_requested' => true]);
    app(ConfirmDonation::class)->handle($other, $accountant);

    $cfdi = Cfdi::query()->sole();
    expect($cfdi->donation_id)->toBe($other->id)->and($cfdi->requested_by_id)->toBeNull()->and($cfdi->status)->toBe(CfdiStatus::Stamped)
        ->and(AuditLog::query()->where('auditable_type', 'cfdi')->where('event', 'created')->sole()->source)->toBe(AuditSource::Console);
});

it('cada mensualidad cobrada es su propio donativo y puede tener su propio CFDI', function (): void {
    $subscription = startFakeMonthlyDonation(['amount' => '300.00']);
    $next = CarbonImmutable::now()->startOfMonth()->addMonth();
    deliverFakeWebhook('invoice.paid', 'payment', fakeGateway()->chargeSubscription((string) $subscription->external_id, $next, FakeScenario::Success));

    $donations = Donation::query()->where('origin', 'online')->get();
    expect($donations)->toHaveCount(2)
        ->and($donations->pluck('payment_id')->unique())->toHaveCount(2);
});

it('la base de datos exige evidencia de timbrado y de cancelación', function (): void {
    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), userWithRole(Role::Administrator))->refresh();

    expect(fn () => DB::transaction(fn () => DB::table('cfdis')->where('id', $cfdi->id)->update(['status' => 'cancelled'])))
        ->toThrow(QueryException::class, 'cfdis_cancellation_evidence')
        ->and(fn () => DB::transaction(fn () => DB::table('cfdis')->where('id', $cfdi->id)->update(['cancellation_motive' => '01'])))
        ->toThrow(QueryException::class, 'cfdis_motive_01_replacement');
});
