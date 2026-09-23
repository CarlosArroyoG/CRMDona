<?php

declare(strict_types=1);

use App\Actions\Cfdi\BuildDonationCfdiDraft;
use App\Actions\Cfdi\DiscardCfdi;
use App\Actions\Cfdi\IssueCfdiAutomatically;
use App\Actions\Cfdi\RequestCfdiCancellation;
use App\Actions\Cfdi\RequestCfdiSubstitution;
use App\Actions\Cfdi\RequestDonationCfdi;
use App\Actions\Cfdi\ResolveDonationFiscalRoute;
use App\Actions\Cfdi\RetryCfdi;
use App\Actions\Donations\ConfirmDonation;
use App\Actions\Refunds\RequestRefund;
use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Data\CancellationResult;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Cfdi\Providers\FakeCfdiProvider;
use App\Enums\AuditSource;
use App\Enums\CfdiStatus;
use App\Enums\DonationStatus;
use App\Enums\FiscalRoute;
use App\Enums\ManualPaymentMethod;
use App\Enums\RefundReason;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Filament\Resources\Cfdis\CfdiResource;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travel;

beforeEach(function (): void {
    Storage::fake('local');
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15', 'donation_legend' => BuildDonationCfdiDraft::DONATARIA_LEGEND,
    ])->save();
});

function onlineDonationWithFunding(string $funding): Donation
{
    startFakeDonation([], FakeScenario::Success);
    $donation = Donation::query()->where('origin', 'online')->latest('id')->firstOrFail();
    $donation->donor()->firstOrFail()->taxProfile()->create(['rfc' => 'AAA010101AAA', 'tax_name' => 'X', 'tax_regime' => TaxRegime::Wages, 'tax_postal_code' => '62000']);
    DB::table('payment_attempts')->where('payment_id', $donation->payment_id)->update(['card_funding' => $funding]);

    return $donation->fresh() ?? $donation;
}

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
        ->toBe(['I', 'PUE', '01', 'D04', '84101600', 'M4', '1', 'Donativo para el fondo general', '01', 'MXN', '1500.00'])
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
    'sin datos fiscales del donante (público en general)' => [fn () => Donation::factory()->confirmed()->create(), 'público en general'],
    'depósito bancario' => [fn () => cfdiReadyDonation(['manual_payment_method' => ManualPaymentMethod::BankDeposit]), 'depósito'],
    'donativo por confirmar' => [fn () => Donation::factory()->create(), 'confirmados'],
    'uso de CFDI incorrecto en el perfil' => [function () {
        $donation = cfdiReadyDonation();
        $donation->donor->taxProfile?->forceFill(['cfdi_use' => 'G03'])->save();

        return $donation->fresh();
    }, 'debe ser D04'],
    'tarjeta de prepago en línea' => [fn () => onlineDonationWithFunding('prepaid'), 'prepago'],
    'reembolso en el pago' => [function () {
        $donation = onlineDonationWithFunding('credit');
        app(RequestRefund::class)->handle($donation->payment()->firstOrFail(), [
            'amount' => '50', 'reason' => RefundReason::DonorRequest->value, 'idempotency_key' => 'r-'.Str::uuid(),
        ], userWithRole(Role::Accountant));

        return $donation->fresh();
    }, 'reembolso'],
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
    runQueueWorker();
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

it('cancelación directa solo con 02 o 03 (01 va por sustitución; 04 es de factura global)', function (string $motive): void {
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

it('emisión automática: al confirmar emite aunque el donante no haya pedido comprobante', function (): void {
    config(['cfdi.auto_issue' => true]);
    $pending = cfdiReadyDonation(['status' => DonationStatus::Pending, 'confirmed_at' => null, 'confirmed_by_id' => null, 'tax_receipt_requested' => false]);

    app(ConfirmDonation::class)->handle($pending, userWithRole(Role::Accountant));

    $cfdi = Cfdi::query()->sole();
    expect($cfdi->donation_id)->toBe($pending->id)->and($cfdi->requested_by_id)->toBeNull()->and($cfdi->status)->toBe(CfdiStatus::Stamped)
        ->and(AuditLog::query()->where('auditable_type', 'cfdi')->where('event', 'created')->sole()->source)->toBe(AuditSource::Console);
});

it('emisión automática: público en general y bloqueos no crean CFDI, y se ven en la ruta fiscal', function (): void {
    config(['cfdi.auto_issue' => true]);
    $accountant = userWithRole(Role::Accountant);
    $public = Donation::factory()->create(['manual_payment_method' => ManualPaymentMethod::Cash, 'tax_receipt_requested' => true]);
    $deposit = cfdiReadyDonation(['status' => DonationStatus::Pending, 'confirmed_at' => null, 'confirmed_by_id' => null, 'manual_payment_method' => ManualPaymentMethod::BankDeposit]);

    expect(app(IssueCfdiAutomatically::class)->handle(app(ConfirmDonation::class)->handle($public, $accountant))?->route)->toBe(FiscalRoute::PublicGeneral)
        ->and(app(ResolveDonationFiscalRoute::class)->handle(app(ConfirmDonation::class)->handle($deposit, $accountant))->route)->toBe(FiscalRoute::Blocked)
        ->and(Cfdi::query()->count())->toBe(0);
});

it('ruta fiscal: individual lista, público en general (sin datos o RFC genérico) y bloqueo', function (): void {
    $route = fn (Donation $donation) => app(ResolveDonationFiscalRoute::class)->handle($donation);
    $generic = cfdiReadyDonation();
    $generic->donor->taxProfile?->forceFill(['rfc' => 'XAXX010101000'])->save();

    expect($route(cfdiReadyDonation())->isReadyToIssue())->toBeTrue()
        ->and($route(Donation::factory()->confirmed()->create())->route)->toBe(FiscalRoute::PublicGeneral)
        ->and($route($generic->fresh() ?? $generic)->route)->toBe(FiscalRoute::PublicGeneral)
        ->and($route(cfdiReadyDonation([
            'kind' => 'in_kind', 'manual_payment_method' => null, 'in_kind_description' => 'Útiles',
            'in_kind_quantity' => '2.000', 'in_kind_unit_code' => 'H87', 'in_kind_product_service_code' => '49101700',
            'in_kind_unit_value' => '250.00', 'in_kind_total_value' => '500.00',
        ]))->route)->toBe(FiscalRoute::Individual);
});

it('arma especie con forma 12 y los datos del bien', function (): void {
    $draft = app(BuildDonationCfdiDraft::class)->handle(cfdiReadyDonation([
        'kind' => 'in_kind', 'manual_payment_method' => null, 'in_kind_description' => 'Útiles',
        'in_kind_quantity' => '1.000', 'in_kind_unit_code' => 'H87', 'in_kind_product_service_code' => '49101700',
        'in_kind_unit_value' => '500.00', 'in_kind_total_value' => '500.00',
    ]));

    expect($draft->paymentForm)->toBe('12')
        ->and($draft->productCode)->toBe('49101700')
        ->and($draft->unitCode)->toBe('H87')
        ->and($draft->quantity)->toBe('1.000')
        ->and($draft->total)->toBe('500.00');
});

it('usa XEXX010101000 para un residente extranjero', function (): void {
    $donation = cfdiReadyDonation();
    $donation->donor->taxProfile?->forceFill(['foreign_resident' => true, 'rfc' => 'ABC123456T12'])->save();

    expect(app(BuildDonationCfdiDraft::class)->handle($donation->fresh() ?? $donation)->receiverRfc)
        ->toBe(BuildDonationCfdiDraft::FOREIGN_RFC);
});

it('donativo en línea: tarjeta de crédito 04 y de débito 28 según el proveedor', function (string $funding, string $form): void {
    expect(app(BuildDonationCfdiDraft::class)->handle(onlineDonationWithFunding($funding))->paymentForm)->toBe($form);
})->with([['credit', '04'], ['debit', '28']]);

it('la conciliación emite los donativos confirmados recientes que siguen sin CFDI', function (): void {
    $donation = cfdiReadyDonation(['confirmed_at' => now()]);
    $old = cfdiReadyDonation(['confirmed_at' => now()->subDays(10)]);
    expect(Cfdi::query()->count())->toBe(0);

    config(['cfdi.auto_issue' => true]);
    dispatch_sync(new ReconcileCfdis);

    expect(Cfdi::query()->pluck('donation_id')->all())->toBe([$donation->id])
        ->and(Cfdi::query()->sole()->status)->toBe(CfdiStatus::Stamped)
        ->and($old->activeCfdi())->toBeNull();
});

it('sustitución (motivo 01): timbra el nuevo relacionado con 04 y después cancela el original con su UUID', function (): void {
    $admin = userWithRole(Role::Administrator);
    $original = app(RequestDonationCfdi::class)->handle($donation = cfdiReadyDonation(), $admin)->refresh();

    $replacement = app(RequestCfdiSubstitution::class)->handle($original, 'Clave de producto equivocada', $admin)->refresh();

    expect($replacement->status)->toBe(CfdiStatus::Stamped)
        ->and($replacement->substitutes_cfdi_id)->toBe($original->id)
        ->and($replacement->replacement_pending)->toBeFalse()
        ->and($original->refresh()->status)->toBe(CfdiStatus::Cancelled)
        ->and($original->cancellation_motive?->value)->toBe('01')
        ->and($original->cancellation_replacement_uuid)->toBe($replacement->uuid)
        ->and($original->cancellation_reason)->toBe('Clave de producto equivocada')
        ->and($donation->activeCfdi()?->id)->toBe($replacement->id)
        ->and((string) Storage::disk('local')->get((string) $replacement->xml_path))
        ->toContain('TipoRelacion="04"')->toContain('UUID="'.$original->uuid.'"');
});

it('sustitución rechazada por el receptor: ambos vigentes y se puede reintentar la cancelación sin timbrar otro', function (): void {
    $admin = userWithRole(Role::Administrator);
    $original = app(RequestDonationCfdi::class)->handle($donation = cfdiReadyDonation(), $admin)->refresh();
    fakePac()->willCancel(CancellationResult::REJECTED);

    $replacement = app(RequestCfdiSubstitution::class)->handle($original, 'Monto capturado mal', $admin)->refresh();

    expect($original->refresh()->status)->toBe(CfdiStatus::Stamped)
        ->and($replacement->status)->toBe(CfdiStatus::Stamped)->and($replacement->replacement_pending)->toBeTrue()
        ->and($donation->activeCfdi()?->id)->toBe($original->id)
        ->and($donation->pendingReplacementCfdi()?->id)->toBe($replacement->id)
        ->and(fn () => app(RequestCfdiCancellation::class)->handle($original, '02', 'Otra razón válida', $admin))->toThrow(ValidationException::class);

    $again = app(RequestCfdiSubstitution::class)->handle($original, 'Monto capturado mal', $admin);

    expect($again->id)->toBe($replacement->id)->and(fakePac()->stampedCount())->toBe(2)
        ->and($original->refresh()->status)->toBe(CfdiStatus::Cancelled)
        ->and($replacement->refresh()->replacement_pending)->toBeFalse();
});

it('descarta un CFDI rechazado que nunca se timbró y permite emitir otro; no descarta uno timbrado', function (): void {
    $admin = userWithRole(Role::Administrator);
    fakePac()->willStamp(FakeCfdiProvider::STAMP_REJECTED);
    $cfdi = app(RequestDonationCfdi::class)->handle($donation = cfdiReadyDonation(), $admin)->refresh();

    app(DiscardCfdi::class)->handle($cfdi, 'Se pidió por error', $admin);

    expect($cfdi->refresh()->status)->toBe(CfdiStatus::Discarded)->and($donation->activeCfdi())->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', 'cfdi')->where('event', 'discarded')->sole()->new_values)->toMatchArray(['reason' => 'Se pidió por error']);

    $stamped = app(RequestDonationCfdi::class)->handle($donation, $admin, 'otra')->refresh();
    expect($stamped->status)->toBe(CfdiStatus::Stamped)
        ->and(fn () => app(DiscardCfdi::class)->handle($stamped, 'No procede', $admin))->toThrow(ValidationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('cfdis')->where('id', $stamped->id)->update(['status' => 'discarded'])))
        ->toThrow(QueryException::class, 'cfdis_discarded_never_stamped');
});

it('la base de datos permite solo una sustitución en curso por donativo', function (): void {
    $original = app(RequestDonationCfdi::class)->handle($donation = cfdiReadyDonation(), userWithRole(Role::Administrator))->refresh();
    $row = ['donation_id' => $donation->id, 'substitutes_cfdi_id' => $original->id, 'replacement_pending' => true, 'substitution_reason' => 'x',
        'provider' => 'fake', 'status' => 'pending', 'total' => '1', 'requested_at' => now()];

    DB::table('cfdis')->insert([...$row, 'idempotency_key' => 's1']);

    expect(fn () => DB::transaction(fn () => DB::table('cfdis')->insert([...$row, 'idempotency_key' => 's2'])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('cfdis')->insert([...$row, 'idempotency_key' => 's3', 'substitution_reason' => null, 'replacement_pending' => false])))
        ->toThrow(QueryException::class, 'cfdis_substitution_reason');
});

it('detalle técnico: Administrador y Contador lo ven; Coordinador no', function (Role $role, bool $sees): void {
    $cfdi = app(RequestDonationCfdi::class)->handle(cfdiReadyDonation(), userWithRole(Role::Administrator))->refresh();
    actingAs(userWithRole($role));

    $response = get(CfdiResource::getUrl('view', ['record' => $cfdi]))->assertOk();
    $sees ? $response->assertSee('Detalle técnico')->assertSee($cfdi->idempotency_key) : $response->assertDontSee('Detalle técnico')->assertDontSee($cfdi->idempotency_key);
})->with([[Role::Administrator, true], [Role::Accountant, true], [Role::FundraisingCoordinator, false]]);

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
