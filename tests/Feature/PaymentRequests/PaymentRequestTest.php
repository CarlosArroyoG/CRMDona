<?php

declare(strict_types=1);

use App\Actions\PaymentRequests\CreatePaymentRequest;
use App\Actions\PaymentRequests\ManagePaymentRequest;
use App\Actions\PaymentRequests\SendPaymentRequest;
use App\Enums\CampaignStatus;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\PaymentRequestStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\TaxRegime;
use App\Mail\DonorMessage;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Program;
use App\Models\Subscription;
use App\Models\User;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function (): void {
    Mail::fake();
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'privacy_notice_url' => 'https://www.fdonbosco.org/aviso-de-privacidad', 'privacy_notice_version' => '2026-09',
    ])->save();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function preparePaymentRequest(array $overrides = [], ?User $actor = null): PaymentRequest
{
    return app(CreatePaymentRequest::class)->handle([
        'donor_id' => $overrides['donor_id'] ?? Donor::factory()->create(['email' => 'lucia@example.com'])->id,
        'amount' => '750.50',
        'frequency' => 'one_time',
        ...$overrides,
    ], $actor ?? userWithRole(Role::FundraisingCoordinator));
}

/**
 * Abre el enlace y devuelve el token de sesión del resumen de /donar.
 */
function openRequestLink(PaymentRequest $request): string
{
    $response = get('/donar/enlace/'.$request->token)->assertRedirect();
    preg_match('#/donar/resumen/([A-Za-z0-9]{40})#', (string) $response->headers->get('Location'), $match);

    return $match[1] ?? throw new RuntimeException('El enlace no llevó al resumen: '.$response->headers->get('Location'));
}

/**
 * @return TestResponse<Response>
 */
function payRequest(string $session, string $scenario = 'success'): TestResponse
{
    return post("/donar/pagar/{$session}", ['fake_scenario' => $scenario]);
}

it('el token es aleatorio: se guarda como hash SHA-256 y cifrado, nunca en claro ni en la bitácora', function (): void {
    $request = preparePaymentRequest();
    $row = DB::table('payment_requests')->where('id', $request->id)->sole();

    expect($request->token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($row->token_hash)->toBe(hash('sha256', $request->token))
        ->and($row->token)->not->toContain($request->token)
        ->and(PaymentRequest::findByToken($request->token)?->id)->toBe($request->id)
        ->and($request->url())->toEndWith('/donar/enlace/'.$request->token)
        ->and($request->url())->not->toContain((string) $request->id.'/')
        ->and($request->status)->toBe(PaymentRequestStatus::Open)
        ->and($request->expires_at->diffInDays(now()->addDays(7)))->toBeLessThan(1)
        ->and(array_keys($request->toArray()))->not->toContain('token', 'token_hash');

    $audit = json_encode(AuditLog::query()->where('auditable_type', 'payment_request')->get()->toArray());
    expect($audit)->not->toContain($request->token);
    expect($audit)->not->toContain(hash('sha256', $request->token));
});

it('preparar un cobro no crea donativo ni pago; el enlace lleva al checkout existente con los datos guardados', function (): void {
    $campaign = Campaign::factory()->create(['name' => 'Becas 2026', 'starts_on' => now()->subDay(), 'ends_on' => now()->addMonth()]);
    $request = preparePaymentRequest(['campaign_id' => $campaign->id, 'tax_receipt_requested' => true]);

    expect(Donation::query()->count())->toBe(0)->and(Payment::query()->count())->toBe(0);

    $session = openRequestLink($request);
    get("/donar/resumen/{$session}")->assertOk()
        ->assertSee('$750.50 MXN')->assertSee('Becas 2026')->assertSee('Una sola vez')
        ->assertSee('datos fiscales que tiene registrados la Fundación')
        ->assertSee('La Fundación preparó este donativo')->assertDontSee('Corregir mis datos');
});

it('pago exitoso confirmado por el proveedor: Donation en línea y solicitud pagada', function (): void {
    $program = Program::factory()->create();
    $request = preparePaymentRequest(['program_id' => $program->id, 'tax_receipt_requested' => true]);

    payRequest(openRequestLink($request));

    $payment = Payment::query()->sole();
    $donation = Donation::query()->sole();
    expect($payment->idempotency_key)->toBe("payment-request:{$request->id}:0")
        ->and($payment->program_id)->toBe($program->id)->and($payment->amount)->toBe('750.50')
        ->and($donation->origin)->toBe(DonationOrigin::Online)->and($donation->status)->toBe(DonationStatus::Confirmed)
        ->and($donation->program_id)->toBe($program->id)->and($donation->tax_receipt_requested)->toBeTrue()
        ->and($donation->registered_by_id)->toBeNull();

    $request->refresh();
    expect($request->status)->toBe(PaymentRequestStatus::Paid)->and($request->payment_id)->toBe($payment->id)->and($request->paid_at)->not->toBeNull();

    // Ya pagada: el enlace deja de servir.
    get('/donar/enlace/'.$request->token)->assertStatus(410)->assertSee('ya se pagó');
});

it('el regreso del navegador no marca nada: la solicitud se paga solo con el webhook del proveedor', function (): void {
    $request = preparePaymentRequest();
    $session = openRequestLink($request);
    payRequest($session, 'pending');

    get('/donar/gracias?status=approved')->assertRedirect("/donar/estado/{$session}");
    expect($request->refresh()->status)->toBe(PaymentRequestStatus::Open)->and(Donation::query()->count())->toBe(0);

    $payment = Payment::query()->sole();
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id);

    expect($request->refresh()->status)->toBe(PaymentRequestStatus::Paid)->and(Donation::query()->count())->toBe(1);
});

it('idempotencia: abrir el enlace varias veces y pagar dos veces reutiliza el mismo Payment', function (): void {
    $request = preparePaymentRequest();

    $first = openRequestLink($request);
    payRequest($first, 'pending');
    $second = openRequestLink($request);
    payRequest($second, 'pending');
    payRequest($second, 'pending');

    expect(Payment::query()->count())->toBe(1)->and($request->refresh()->attempt)->toBe(0)
        ->and($request->payment_id)->toBe(Payment::query()->sole()->id);
});

it('pasadas 24 h con un pago abierto, el enlace inicia un intento nuevo (la sesión del proveedor ya venció)', function (): void {
    $request = preparePaymentRequest();
    payRequest(openRequestLink($request), 'pending');
    Payment::query()->update(['created_at' => now()->subHours(25)]);

    payRequest(openRequestLink($request), 'pending');

    expect(Payment::query()->pluck('idempotency_key')->sort()->values()->all())->toBe(["payment-request:{$request->id}:0", "payment-request:{$request->id}:1"]);
});

it('pago rechazado: no hay donativo, la solicitud sigue abierta y el reintento existente cobra con otro intento', function (): void {
    $request = preparePaymentRequest();
    $session = openRequestLink($request);

    payRequest($session, 'declined');
    get("/donar/estado/{$session}")->assertSee('No se pudo completar el pago');
    expect(Donation::query()->count())->toBe(0)->and($request->refresh()->status)->toBe(PaymentRequestStatus::Open);

    $retry = post("/donar/reintentar/{$session}");
    preg_match('#/donar/resumen/([A-Za-z0-9]{40})#', (string) $retry->headers->get('Location'), $match);
    payRequest($match[1] ?? '');

    expect(Payment::query()->count())->toBe(2)->and(Donation::query()->count())->toBe(1)
        ->and($request->refresh()->status)->toBe(PaymentRequestStatus::Paid)
        ->and($request->payment_id)->toBe(Payment::query()->where('idempotency_key', "payment-request:{$request->id}:1")->value('id'));
});

it('mensual: el enlace crea la Subscription existente; el primer cobro confirmado paga la solicitud', function (): void {
    $request = preparePaymentRequest(['frequency' => 'monthly', 'amount' => '300']);
    $session = openRequestLink($request);

    get("/donar/resumen/{$session}")->assertSee('recurrente')->assertSee('$300.00 MXN al mes');
    payRequest($session);

    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)->and($subscription->idempotency_key)->toBe("payment-request:{$request->id}:0")
        ->and(Donation::query()->sole()->payment?->subscription_id)->toBe($subscription->id);

    $request->refresh();
    expect($request->status)->toBe(PaymentRequestStatus::Paid)->and($request->subscription_id)->toBe($subscription->id);
});

it('manipulación: importe, frecuencia, destino o donante enviados por el navegador se ignoran', function (): void {
    $request = preparePaymentRequest();
    $other = Donor::factory()->create();
    $campaign = Campaign::factory()->create();

    $session = openRequestLink($request);
    post("/donar/pagar/{$session}", ['fake_scenario' => 'success', 'amount' => '1', 'frequency' => 'monthly', 'campaign_id' => $campaign->id, 'donor_id' => $other->id]);
    get("/donar/enlace/{$request->token}?amount=1&frequency=monthly")->assertStatus(410);

    $payment = Payment::query()->sole();
    expect($payment->amount)->toBe('750.50')->and($payment->donor_id)->toBe($request->donor_id)
        ->and($payment->campaign_id)->toBeNull()->and(Subscription::query()->count())->toBe(0);
});

it('un token inventado, vencido, cancelado o regenerado no da acceso', function (): void {
    get('/donar/enlace/'.Str::random(40))->assertNotFound();
    get('/donar/enlace/123')->assertNotFound();

    $expired = preparePaymentRequest();
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    get('/donar/enlace/'.$expired->token)->assertStatus(410)->assertSee('venció');
    expect($expired->effectiveStatus())->toBe(PaymentRequestStatus::Expired);

    $coordinator = userWithRole(Role::FundraisingCoordinator);
    $cancelled = preparePaymentRequest();
    app(ManagePaymentRequest::class)->cancel($cancelled, $coordinator);
    get('/donar/enlace/'.$cancelled->token)->assertStatus(410);

    $regenerated = preparePaymentRequest();
    $oldToken = $regenerated->token;
    $fresh = app(ManagePaymentRequest::class)->regenerate($regenerated, $coordinator);
    expect($fresh->token)->not->toBe($oldToken)->and($fresh->token_version)->toBe(2);
    get('/donar/enlace/'.$oldToken)->assertNotFound();
    get('/donar/enlace/'.$fresh->token)->assertRedirect();

    // Una vencida se puede regenerar con 7 días nuevos; una cancelada ya no cambia.
    expect(app(ManagePaymentRequest::class)->regenerate($expired, $coordinator)->isUsable())->toBeTrue();
    expect(fn () => app(ManagePaymentRequest::class)->regenerate($cancelled, $coordinator))->toThrow(ValidationException::class);
});

it('cancelar mientras el donante veía el resumen impide cobrar; si el proveedor ya había cobrado, queda pagada', function (): void {
    $request = preparePaymentRequest();
    $session = openRequestLink($request);
    app(ManagePaymentRequest::class)->cancel($request, userWithRole(Role::Administrator));

    payRequest($session)->assertRedirect("/donar/resumen/{$session}");
    expect(Payment::query()->count())->toBe(0);

    $inFlight = preparePaymentRequest();
    payRequest(openRequestLink($inFlight), 'pending');
    app(ManagePaymentRequest::class)->cancel($inFlight, userWithRole(Role::Administrator));
    $payment = Payment::query()->sole();
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id);

    expect($inFlight->refresh()->status)->toBe(PaymentRequestStatus::Paid);
});

it('valida destino y disponibilidad al preparar: campaña cerrada, donante archivado o sin proveedor', function (): void {
    $closed = Campaign::factory()->create(['status' => CampaignStatus::Archived]);
    expect(fn () => preparePaymentRequest(['campaign_id' => $closed->id]))->toThrow(ValidationException::class);

    $archived = Donor::factory()->create(['archived_at' => now()]);
    expect(fn () => preparePaymentRequest(['donor_id' => $archived->id]))->toThrow(ValidationException::class);

    OrganizationSetting::current()->forceFill(['privacy_notice_url' => null, 'privacy_notice_version' => null])->save();
    expect(fn () => preparePaymentRequest())->toThrow(ValidationException::class, 'no están disponibles');
});

it('RBAC: solo Administrador y Coordinador preparan, cancelan, regeneran y envían; el Contador y Solo lectura no', function (Role $role, bool $allowed): void {
    $user = userWithRole($role);
    $request = preparePaymentRequest();

    $attempts = [
        fn () => preparePaymentRequest(actor: $user),
        fn () => app(SendPaymentRequest::class)->handle($request, $user),
        fn () => app(ManagePaymentRequest::class)->regenerate($request, $user),
        fn () => app(ManagePaymentRequest::class)->cancel($request, $user),
    ];

    foreach ($attempts as $attempt) {
        $allowed ? expect($attempt)->not->toThrow(AuthorizationException::class) : expect($attempt)->toThrow(AuthorizationException::class);
    }
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, true],
    'Contador' => [Role::Accountant, false],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('correo de solicitud: individual, transaccional, con botón seguro; el enlace no queda en el historial ni en la bitácora', function (): void {
    $donor = Donor::factory()->create(['email' => 'maria@example.com', 'first_name' => 'María', 'accepts_communications' => false]);
    $request = preparePaymentRequest(['donor_id' => $donor->id, 'frequency' => 'monthly', 'amount' => '250']);
    $admin = userWithRole(Role::Administrator);

    $communication = app(SendPaymentRequest::class)->handle($request, $admin);

    expect($communication->refresh()->status)->toBe(CommunicationStatus::Sent)
        ->and($communication->kind)->toBe(CommunicationKind::PaymentRequest)
        ->and($communication->payment_request_id)->toBe($request->id)
        ->and($communication->requested_by_id)->toBe($admin->id);

    Mail::assertSent(DonorMessage::class, function (DonorMessage $mail) use ($request): bool {
        $html = $mail->render();

        return $mail->hasTo('maria@example.com')
            && str_contains($html, 'Hola, María') && str_contains($html, '$250.00 MXN') && str_contains($html, 'cada mes')
            && str_contains($html, 'Completar mi donativo') && str_contains($html, e($request->url()))
            && str_contains($html, 'FUNDACION DE PRUEBA') && ! str_contains($html, 'Darme de baja');
    });

    $stored = json_encode([Communication::query()->get()->toArray(), AuditLog::query()->get()->toArray()]);
    expect($stored)->not->toContain($request->token);
});

it('el correo no sale si la solicitud ya no está vigente o si el donante no tiene correo', function (): void {
    $admin = userWithRole(Role::Administrator);
    $request = preparePaymentRequest();
    $communication = app(SendPaymentRequest::class)->handle($request, $admin);
    Mail::assertSentCount(1);

    app(ManagePaymentRequest::class)->cancel($request, $admin);
    expect(fn () => app(SendPaymentRequest::class)->handle($request->refresh(), $admin))->toThrow(ValidationException::class);

    $noEmail = preparePaymentRequest(['donor_id' => Donor::factory()->create(['email' => null])->id]);
    expect(fn () => app(SendPaymentRequest::class)->handle($noEmail, $admin))->toThrow(ValidationException::class, 'no tiene correo');
    expect($communication->refresh()->status)->toBe(CommunicationStatus::Sent);
});

it('ningún dato de tarjeta ni token llega a la base, la bitácora o el historial', function (): void {
    $request = preparePaymentRequest();
    post('/donar/pagar/'.openRequestLink($request), ['fake_scenario' => 'success', 'card_number' => '4242424242424242', 'cvc' => '123']);

    $dump = json_encode([
        DB::table('payments')->get(), DB::table('payment_attempts')->get(), DB::table('payment_requests')->get(),
        DB::table('audit_logs')->get(), DB::table('communications')->get(), DB::table('donations')->get(),
    ]);
    foreach (['4242424242424242', '"cvc"', $request->token] as $secret) {
        expect($dump)->not->toContain($secret);
    }
});
