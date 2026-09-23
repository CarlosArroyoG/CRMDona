<?php

declare(strict_types=1);

use App\Actions\Cfdi\RequestDonationCfdi;
use App\Actions\Incidents\OpenPaymentIncident;
use App\Actions\Incidents\ResolveIncident;
use App\Cfdi\CfdiProviderRegistry;
use App\Cfdi\Providers\FakeCfdiProvider;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\ManualPaymentMethod;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Filament\Widgets\FundraisingOverview;
use App\Jobs\SendPaymentIncidentAlert;
use App\Listeners\ReportFailedJob;
use App\Mail\DonorMessage;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Export;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Providers\AppServiceProvider;
use App\Reports\DashboardMetrics;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery\MockInterface;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    Mail::fake();
});

// --- Cabeceras y CSP --------------------------------------------------------

it('la página pública lleva CSP estricta con nonce, sin unsafe-eval, y cabeceras de seguridad', function (): void {
    OrganizationSetting::current()->forceFill(['privacy_notice_url' => 'https://example.org/aviso', 'privacy_notice_version' => '1'])->save();

    $response = get('/donar')->assertOk();
    $csp = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", $csp, $nonce);

    expect($csp)->toContain("default-src 'self'")->toContain("object-src 'none'")->toContain("frame-ancestors 'self'")
        ->toContain('https://js.stripe.com')->toContain('https://sdk.mercadopago.com')
        ->and(str_contains($csp, 'unsafe-eval') || str_contains($csp, "script-src 'self' 'unsafe-inline'"))->toBeFalse()
        ->and((string) $response->getContent())->toContain('nonce="'.($nonce[1] ?? 'x').'"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('el panel aplica las directivas que no rompen Filament y deja la política de scripts en Report-Only (sin unsafe-eval)', function (): void {
    actingAs(userWithRole(Role::Administrator));

    $response = get('/admin')->assertOk();

    $enforced = (string) $response->headers->get('Content-Security-Policy');
    $reportOnly = (string) $response->headers->get('Content-Security-Policy-Report-Only');

    expect($enforced)->toContain("frame-ancestors 'self'")->toContain("object-src 'none'")
        ->and($reportOnly)->toContain("script-src 'self' 'nonce-")
        ->and(str_contains($enforced.$reportOnly, 'unsafe-eval'))->toBeFalse();
});

it('HSTS solo en producción y por HTTPS', function (): void {
    app()->detectEnvironment(fn () => 'production');
    $secure = get('https://localhost/up');
    $plain = get('http://localhost/up');
    app()->detectEnvironment(fn () => 'testing');

    expect($secure->headers->get('Strict-Transport-Security'))->toBe('max-age=31536000')
        ->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();
});

// --- Sesión y acceso ---------------------------------------------------------

it('el login limita los intentos (5 por minuto)', function (): void {
    $user = userWithRole(Role::Accountant);
    $component = Livewire::test(Login::class);

    foreach (range(1, 5) as $i) {
        $component->fillForm(['email' => $user->email, 'password' => 'incorrecta-'.$i])->call('authenticate');
    }
    // Sexto intento: Filament lo bloquea y avisa, aun con la contraseña correcta.
    $component->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertNotified();

    expect(auth()->check())->toBeFalse();
});

it('Solo lectura no abre fichas protegidas por URL directa ni ejecuta acciones ocultas', function (): void {
    $donation = Donation::factory()->confirmed()->create(['donor_id' => Donor::factory()->withTaxProfile()->create()->id, 'manual_payment_method' => ManualPaymentMethod::Cash]);
    OrganizationSetting::current()->forceFill(['legal_name' => 'F', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities, 'tax_postal_code' => '62000', 'authorization_number' => '1', 'authorization_date' => '2026-01-15'])->save();
    $cfdi = app(RequestDonationCfdi::class)->handle($donation, userWithRole(Role::Administrator))->refresh();
    $incident = app(OpenPaymentIncident::class)->handle(IncidentType::cases()[0], 'test:1', payment: Payment::factory()->create());
    actingAs(userWithRole(Role::ReadOnly));

    foreach ([
        "/admin/cfdis/{$cfdi->id}", "/admin/payment-incidents/{$incident->id}", '/admin/communications', '/admin/audit-logs',
        '/admin/refunds', '/admin/webhook-events', '/admin/reporte-cfdi', route('cfdi.files', [$cfdi, 'xml']),
    ] as $url) {
        get($url)->assertForbidden();
    }

    Livewire::test(ListDonations::class)->assertActionHidden(TestAction::make('export')->table());
    expect(Export::query()->count())->toBe(0);
});

it('los webhooks sin firma válida se rechazan y no guardan el payload', function (): void {
    postJson('/webhooks/payments/fake', ['id' => 'evt_x', 'card' => '4242424242424242'])->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0);
});

// --- Protección de entornos ----------------------------------------------------

it('bloquea comandos destructivos contra `crm` y en producción; permite migrate normal y las bases desechables', function (): void {
    $provider = new AppServiceProvider(app());
    $apply = fn () => (new ReflectionMethod($provider, 'prohibitDestructiveCommandsOutsideDisposableDatabases'))->invoke($provider);
    $connection = config('database.default');

    config(["database.connections.{$connection}.database" => 'crm']);
    $apply();
    expect(Artisan::call('db:wipe', ['--force' => true]))->toBe(1)
        ->and(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(1);

    config(["database.connections.{$connection}.database" => 'crm_testing']);
    app()->detectEnvironment(fn () => 'production');
    $apply();
    expect(Artisan::call('migrate:rollback', ['--force' => true, '--pretend' => true]))->toBe(1);
    app()->detectEnvironment(fn () => 'testing');

    $apply();
    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Artisan::call('migrate:rollback', ['--force' => true, '--pretend' => true]))->toBe(0);
});

// --- RF-01 y alertas operativas -----------------------------------------------

it('RF-01: la incidencia llega a la campana y por correo a los responsables configurados, una sola vez; leer no resuelve', function (): void {
    $admin = userWithRole(Role::Administrator);
    $accountant = userWithRole(Role::Accountant);
    $accountant->forceFill(['receives_payment_alerts' => true])->save();
    $optedOut = userWithRole(Role::Accountant);
    $readOnly = userWithRole(Role::ReadOnly);
    $payment = Payment::factory()->create();

    $incident = app(OpenPaymentIncident::class)->handle(IncidentType::cases()[0], 'rf01:1', payment: $payment);
    app(OpenPaymentIncident::class)->handle(IncidentType::cases()[0], 'rf01:1', payment: $payment);
    dispatch_sync(new SendPaymentIncidentAlert($incident->id));

    $mails = Mail::sent(DonorMessage::class);
    expect($mails->map(fn (DonorMessage $mail): string => (string) $mail->to[0]['address'])->sort()->values()->all())
        ->toBe(collect([$admin->email, $accountant->email])->sort()->values()->all())
        ->and($mails->first()?->message->subject)->toStartWith('[CRM] Incidencia de pago')
        ->and($mails->first()?->message->body)->toContain('/admin/payment-incidents/'.$incident->id)
        ->and($admin->notifications()->count())->toBe(1)->and($accountant->notifications()->count())->toBe(1)
        ->and($optedOut->notifications()->count())->toBe(0)->and($readOnly->notifications()->count())->toBe(0);

    $admin->unreadNotifications->markAsRead();
    expect($incident->refresh()->status)->toBe(IncidentStatus::New);
    actingAs($admin);
    app(ResolveIncident::class)->handle($incident, 'Revisado con el proveedor.', $admin);
    expect($incident->refresh()->status)->toBe(IncidentStatus::Resolved);
});

it('CFDI rechazado: aviso de intervención (campana y correo) a quien emite CFDI, una vez', function (): void {
    OrganizationSetting::current()->forceFill(['legal_name' => 'F', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities, 'tax_postal_code' => '62000', 'authorization_number' => '1', 'authorization_date' => '2026-01-15'])->save();
    $admin = userWithRole(Role::Administrator);
    $accountant = userWithRole(Role::Accountant);
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    /** @var FakeCfdiProvider $pac */
    $pac = app(CfdiProviderRegistry::class)->current();
    $pac->willStamp(FakeCfdiProvider::STAMP_REJECTED);

    $donation = Donation::factory()->confirmed()->create(['donor_id' => Donor::factory()->withTaxProfile()->create()->id, 'manual_payment_method' => ManualPaymentMethod::Cash]);
    app(RequestDonationCfdi::class)->handle($donation, $admin);

    expect($admin->notifications()->count())->toBe(1)->and($accountant->notifications()->count())->toBe(1)
        ->and($coordinator->notifications()->count())->toBe(0)
        ->and(Mail::sent(DonorMessage::class)->filter(fn (DonorMessage $mail): bool => str_contains($mail->message->subject, 'CFDI rechazado'))->count())->toBe(2);
});

it('un Job que agota sus intentos deja log crítico sin payload y avisa a los Administradores, uno por hora', function (): void {
    $admin = userWithRole(Role::Administrator);
    $accountant = userWithRole(Role::Accountant);
    $log = Log::spy();
    /** @var Job&MockInterface $job */
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendCommunication');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(3);
    $event = new JobFailed('redis', $job, new RuntimeException('SMTP caído para tarjeta 4242424242424242'));

    app(ReportFailedJob::class)->handle($event);
    app(ReportFailedJob::class)->handle($event);

    $log->shouldHaveReceived('critical')->withArgs(fn (string $message, array $context): bool => $context['job'] === 'App\\Jobs\\SendCommunication'
        && ! str_contains((string) json_encode($context), '4242424242424242'));
    expect($admin->notifications()->count())->toBe(1)->and($accountant->notifications()->count())->toBe(0);
});

// --- Métrica del tablero (comparación homogénea) -------------------------------

it('compara del día 1 a hoy contra los mismos días del mes anterior, recortando meses más cortos', function (string $today, string $from, string $to): void {
    $periods = app(DashboardMetrics::class)->comparablePeriods(CarbonImmutable::parse($today));

    expect($periods['previous'][0]->toDateString())->toBe($from)->and($periods['previous'][1]->toDateString())->toBe($to)
        ->and($periods['current'][0]->toDateString())->toBe(substr($today, 0, 8).'01');
})->with([
    ['2026-09-15', '2026-08-01', '2026-08-15'],
    ['2026-03-31', '2026-02-01', '2026-02-28'],
    ['2028-03-30', '2028-02-01', '2028-02-29'],
    ['2026-01-10', '2025-12-01', '2025-12-10'],
    ['2026-05-31', '2026-04-01', '2026-04-30'],
]);

it('la comparación del tablero usa los mismos días del mes anterior', function (): void {
    travelTo(CarbonImmutable::parse('2026-09-10 12:00'));
    Donation::factory()->confirmed()->create(['amount' => '100.00', 'received_on' => '2026-09-05']);
    Donation::factory()->confirmed()->create(['amount' => '100.00', 'received_on' => '2026-08-05']);
    Donation::factory()->confirmed()->create(['amount' => '900.00', 'received_on' => '2026-08-25']); // fuera de la ventana 1–10

    $metrics = app(DashboardMetrics::class);
    $periods = $metrics->comparablePeriods(CarbonImmutable::now());

    expect($metrics->changePercent($metrics->raisedBetween(...$periods['current']), $metrics->raisedBetween(...$periods['previous'])))->toBe('0.0')
        ->and($metrics->raisedInMonth(CarbonImmutable::now()->subMonth()))->toBe('1000.00');
    actingAs(userWithRole(Role::Administrator));
    Livewire::test(FundraisingOverview::class)->assertSee('vs 1 al 10 de agosto');
});
