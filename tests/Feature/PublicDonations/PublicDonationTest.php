<?php

declare(strict_types=1);

use App\Actions\Cfdi\ResolveDonationFiscalRoute;
use App\Enums\AuditSource;
use App\Enums\CampaignStatus;
use App\Enums\CfdiStatus;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\DonorOrigin;
use App\Enums\FiscalRoute;
use App\Enums\PaymentStatus;
use App\Enums\ProgramStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Enums\TaxRegime;
use App\Filament\Resources\Campaigns\CampaignResource;
use App\Mail\DonorMessage;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Subscription;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withSession;

beforeEach(function (): void {
    Mail::fake();
    config(['donations.public.min_seconds_to_submit' => 0, 'donations.public.suggested_amounts' => ['200', '500', '1000']]);
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15',
        'privacy_notice_url' => 'https://www.fdonbosco.org/aviso-de-privacidad', 'privacy_notice_version' => '2026-09',
    ])->save();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function donationForm(array $overrides = []): array
{
    return [
        'frequency' => 'one_time', 'amount' => '500', 'donor_type' => 'individual',
        'first_name' => 'Lucía', 'last_name' => 'Hernández', 'email' => 'lucia.hernandez@example.com',
        'privacy_accepted' => '1', ...$overrides,
    ];
}

/**
 * Envía el formulario (con la sesión del formulario abierto) y devuelve el token del resumen.
 *
 * @param  array<string, mixed>  $overrides
 */
function submitDonation(array $overrides = [], string $url = '/donar'): string
{
    $response = withSession(['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()])->post($url, donationForm($overrides));
    $response->assertRedirect();
    preg_match('#/donar/resumen/([A-Za-z0-9]{40})#', (string) $response->headers->get('Location'), $match);

    return $match[1] ?? throw new RuntimeException('No redirigió al resumen: '.$response->headers->get('Location'));
}

/**
 * @return TestResponse<Response>
 */
function payDonation(string $token, string $scenario = 'success'): TestResponse
{
    return post("/donar/pagar/{$token}", ['fake_scenario' => $scenario]);
}

it('muestra la página pública con identidad, privacidad, consentimiento sin preseleccionar, anti-spam y semántica accesible', function (): void {
    $html = get('/donar')->assertOk()->getContent();

    expect($html)->toContain('<html lang="es-MX">')->toContain('FUNDACION DE PRUEBA')
        ->toContain('href="https://www.fdonbosco.org/aviso-de-privacidad"')
        ->toContain('name="_token"')->toContain('name="website"')
        ->toContain('<legend')->toContain('<label for="email"')->toContain('name="viewport"')
        ->toContain('value="500"')->toContain('value="otro"')
        ->toContain('Cada mes (donativo recurrente)');
    expect(preg_match('/name="accepts_communications" value="1"\s+checked/', (string) $html))->toBe(0)
        ->and(in_array('web', Route::getRoutes()->getByName('donate.store')?->gatherMiddleware() ?? [], true))->toBeTrue();
});

it('sin aviso de privacidad configurado no se puede donar', function (): void {
    OrganizationSetting::current()->forceFill(['privacy_notice_url' => null, 'privacy_notice_version' => null])->save();

    get('/donar')->assertStatus(503)->assertSee('no están disponibles');
});

it('campaña válida muestra su nombre; inválida, archivada, terminada, fuera de fechas o con programa archivado no permite donar', function (): void {
    $valid = Campaign::factory()->create(['name' => 'Becas 2026', 'slug' => 'becas-2026', 'starts_on' => now()->subDay(), 'ends_on' => now()->addMonth()]);
    get('/donar/campana/becas-2026')->assertOk()->assertSee('Becas 2026');

    $invalid = [
        Campaign::factory()->create(['slug' => 'borrador', 'status' => CampaignStatus::Draft]),
        Campaign::factory()->create(['slug' => 'archivada', 'status' => CampaignStatus::Archived]),
        Campaign::factory()->create(['slug' => 'terminada', 'status' => CampaignStatus::Finished]),
        Campaign::factory()->create(['slug' => 'vencida', 'starts_on' => now()->subMonths(3), 'ends_on' => now()->subDay()]),
        Campaign::factory()->create(['slug' => 'futura', 'starts_on' => now()->addWeek(), 'ends_on' => now()->addMonth()]),
        Campaign::factory()->create(['slug' => 'programa-archivado', 'starts_on' => null, 'ends_on' => null,
            'program_id' => Program::factory()->create(['status' => ProgramStatus::Archived])->id]),
    ];
    foreach ([...array_map(fn (Campaign $campaign): string => $campaign->slug, $invalid), 'no-existe'] as $slug) {
        get("/donar/campana/{$slug}")->assertNotFound()->assertSee('no está recibiendo donativos');
    }

    withSession(['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()])
        ->post('/donar/campana/archivada', donationForm())->assertStatus(422);
    expect(Payment::query()->count())->toBe(0)->and($valid->acceptsDonations())->toBeTrue();
});

it('E2E único con FakeGateway: página → pago → Payment → Donation → recibo → agradecimiento; donante nuevo sin usuario del CRM', function (): void {
    $campaign = Campaign::factory()->create(['slug' => 'becas', 'starts_on' => null, 'ends_on' => null]);
    $token = submitDonation(['accepts_communications' => '1'], '/donar/campana/becas');

    get("/donar/resumen/{$token}")->assertOk()->assertSee('$500.00 MXN')->assertSee('Una sola vez')->assertSee('Proveedor simulado');
    payDonation($token)->assertRedirect("/donar/estado/{$token}");
    get("/donar/estado/{$token}")->assertOk()->assertSee('¡Gracias por tu donativo!');

    $payment = Payment::query()->sole();
    $donation = Donation::query()->sole();
    $donor = Donor::query()->sole();
    expect($payment->status)->toBe(PaymentStatus::Succeeded)->and($payment->amount)->toBe('500.00')->and($payment->campaign_id)->toBe($campaign->id)
        ->and($donation->origin)->toBe(DonationOrigin::Online)->and($donation->status)->toBe(DonationStatus::Confirmed)
        ->and($donation->campaign_id)->toBe($campaign->id)->and($donation->payment_id)->toBe($payment->id)
        ->and($donor->origin)->toBe(DonorOrigin::PublicPage)->and($donor->registered_by_id)->toBeNull()
        ->and($donor->accepts_communications)->toBeTrue()->and($donor->privacy_notice_version)->toBe('2026-09')
        ->and(DonationReceipt::query()->where('donation_id', $donation->id)->exists())->toBeTrue()
        ->and(app(ResolveDonationFiscalRoute::class)->handle($donation)->route)->toBe(FiscalRoute::PublicGeneral)
        ->and(AuditLog::query()->where('auditable_type', 'donor')->where('event', 'created')->sole()->source)->toBe(AuditSource::Donor);
    Mail::assertSent(DonorMessage::class, fn (DonorMessage $mail): bool => $mail->hasTo('lucia.hernandez@example.com'));
});

it('E2E con datos fiscales y CFDI automático: ruta individual y CFDI timbrado con FakeCfdiProvider', function (): void {
    config(['cfdi.auto_issue' => true]);
    $token = submitDonation(['wants_tax_receipt' => '1', 'rfc' => 'HELU800101AB1', 'tax_name' => 'LUCIA HERNANDEZ',
        'tax_regime' => '605', 'tax_postal_code' => '62000']);

    payDonation($token);

    $donation = Donation::query()->sole();
    expect($donation->donor->taxProfile?->rfc)->toBe('HELU800101AB1')
        ->and(Cfdi::query()->sole()->status)->toBe(CfdiStatus::Stamped)
        ->and(Cfdi::query()->sole()->donation_id)->toBe($donation->id);
    get("/donar/estado/{$token}")->assertSee('comprobante fiscal')->assertDontSee('HELU800101AB1');
});

it('E2E mensual: explica la recurrencia y crea Subscription → primer Payment → Donation', function (): void {
    $token = submitDonation(['frequency' => 'monthly', 'amount' => '200']);

    get("/donar/resumen/{$token}")->assertSee('recurrente')->assertSee('cada mes')->assertSee('$200.00 MXN al mes');
    payDonation($token);
    get("/donar/estado/{$token}")->assertSee('¡Gracias por tu donativo!')->assertSee('mensual');

    $subscription = Subscription::query()->sole();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)->and($subscription->amount)->toBe('200.00')
        ->and(Payment::query()->sole()->subscription_id)->toBe($subscription->id)
        ->and(Donation::query()->sole()->payment_id)->toBe(Payment::query()->sole()->id);
});

it('pago rechazado: no hay Donation ni se afirma el pago; reintentar crea un pago nuevo y sí se confirma', function (): void {
    $token = submitDonation();

    payDonation($token, 'declined')->assertRedirect("/donar/estado/{$token}");
    get("/donar/estado/{$token}")->assertSee('No se pudo completar el pago')->assertDontSee('Gracias por tu donativo');
    expect(Donation::query()->count())->toBe(0);

    $retry = post("/donar/reintentar/{$token}");
    preg_match('#/donar/resumen/([A-Za-z0-9]{40})#', (string) $retry->headers->get('Location'), $match);
    payDonation($match[1] ?? '');

    expect(Payment::query()->count())->toBe(2)->and(Donation::query()->count())->toBe(1)
        ->and(Donor::query()->count())->toBe(1);
});

it('regresar del proveedor no confirma nada: pendiente hasta que el webhook lo confirma', function (): void {
    $token = submitDonation();
    payDonation($token, 'pending');

    get('/donar/gracias?status=approved&payment_id=999')->assertRedirect("/donar/estado/{$token}");
    get("/donar/estado/{$token}")->assertSee('Estamos confirmando tu pago')->assertDontSee('¡Gracias por tu donativo!');
    expect(Donation::query()->count())->toBe(0);

    $payment = Payment::query()->sole();
    fakeGateway()->donorAttempt((string) $payment->external_id, FakeScenario::Success);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id);
    deliverFakeWebhook('payment.succeeded', 'payment', (string) $payment->external_id);

    get("/donar/estado/{$token}")->assertSee('¡Gracias por tu donativo!');
    expect(Donation::query()->count())->toBe(1);
});

it('doble envío, recarga y volver atrás no duplican Payment, Donation ni donante', function (): void {
    $token = submitDonation();

    payDonation($token);
    payDonation($token);
    get("/donar/resumen/{$token}")->assertRedirect("/donar/estado/{$token}");

    expect(Payment::query()->count())->toBe(1)->and(Donation::query()->count())->toBe(1)->and(Donor::query()->count())->toBe(1);
});

it('manipulación: importe fuera de las opciones, límites de la organización, parámetros extra al pagar y campaña en el cuerpo', function (): void {
    $campaign = Campaign::factory()->create(['starts_on' => null, 'ends_on' => null]);
    OrganizationSetting::current()->forceFill(['online_donation_min_amount' => '50.00', 'online_donation_max_amount' => '10000.00'])->save();
    $session = ['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()];

    withSession($session)->post('/donar', donationForm(['amount' => '1']))->assertSessionHasErrors('amount');
    withSession($session)->post('/donar', donationForm(['amount' => 'otro', 'custom_amount' => '10']))->assertSessionHasErrors('custom_amount');
    withSession($session)->post('/donar', donationForm(['amount' => 'otro', 'custom_amount' => '20000']))->assertSessionHasErrors('custom_amount');
    withSession($session)->post('/donar', donationForm(['frequency' => 'weekly']))->assertSessionHasErrors('frequency');

    $token = submitDonation(['amount' => 'otro', 'custom_amount' => '750.50', 'campaign_id' => $campaign->id]);
    post("/donar/pagar/{$token}", ['fake_scenario' => 'success', 'amount' => '1', 'campaign_id' => $campaign->id, 'frequency' => 'monthly', 'donor_id' => 1]);

    $payment = Payment::query()->sole();
    expect($payment->amount)->toBe('750.50')->and($payment->campaign_id)->toBeNull()->and($payment->kind->value)->toBe('one_time')
        ->and(Subscription::query()->count())->toBe(0);
});

it('un token de otra sesión o inventado no da acceso', function (): void {
    get('/donar/resumen/'.str_repeat('a', 40))->assertNotFound();
    post('/donar/pagar/'.str_repeat('b', 40))->assertNotFound();
    get('/donar/estado/1')->assertNotFound();
});

it('anti-spam: el campo trampa o un envío demasiado rápido no avanzan; límite de envíos por minuto', function (): void {
    config(['donations.public.min_seconds_to_submit' => 3]);
    withSession(['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()])
        ->post('/donar', donationForm(['website' => 'http://spam.example']))->assertSessionHasErrors('form');
    withSession(['public_donation_form_opened_at' => now()->getTimestamp()])
        ->post('/donar', donationForm())->assertSessionHasErrors('form');
    post('/donar', donationForm())->assertSessionHasErrors('form'); // sin haber abierto el formulario

    config(['donations.public.rate_limit_per_minute' => 2]);
    post('/donar', donationForm());
    post('/donar', donationForm())->assertStatus(429);
});

it('privacidad obligatoria y comunicaciones opcionales (sin marcar = sin consentimiento)', function (): void {
    withSession(['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()])
        ->post('/donar', donationForm(['privacy_accepted' => null]))->assertSessionHasErrors('privacy_accepted');

    payDonation(submitDonation());

    expect(Donor::query()->sole()->accepts_communications)->toBeFalse();
});

it('donante existente por correo: se reutiliza sin modificar sus datos ni revelarlo; datos fiscales distintos quedan en nota interna', function (): void {
    $existing = Donor::factory()->withTaxProfile()->create(['email' => 'lucia.hernandez@example.com', 'first_name' => 'Nombre Registrado', 'accepts_communications' => false]);
    $rfc = $existing->taxProfile?->rfc;

    $token = submitDonation(['email' => 'LUCIA.hernandez@example.com', 'accepts_communications' => '1', 'wants_tax_receipt' => '1',
        'rfc' => 'HELU800101AB1', 'tax_name' => 'OTRA PERSONA', 'tax_regime' => '605', 'tax_postal_code' => '62000']);
    get("/donar/resumen/{$token}")->assertDontSee('Nombre Registrado');
    payDonation($token);
    get("/donar/estado/{$token}")->assertDontSee('Nombre Registrado');

    $existing->refresh();
    expect(Donor::query()->count())->toBe(1)
        ->and(Donation::query()->sole()->donor_id)->toBe($existing->id)
        ->and($existing->first_name)->toBe('Nombre Registrado')
        ->and($existing->accepts_communications)->toBeFalse()
        ->and($existing->taxProfile?->rfc)->toBe($rfc)
        ->and((string) $existing->notes)->toContain('datos fiscales distintos')->toContain('acepto recibir comunicaciones')
        ->and((string) $existing->notes)->not->toContain('HELU800101AB1');
});

it('datos fiscales: se validan con las reglas existentes; el RFC repetido en otro donante no se fusiona', function (): void {
    $session = ['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()];
    withSession($session)->post('/donar', donationForm(['wants_tax_receipt' => '1', 'rfc' => 'NOVALIDO', 'tax_name' => 'X', 'tax_regime' => '605', 'tax_postal_code' => '62000']))
        ->assertSessionHasErrors('rfc');

    $other = Donor::factory()->withTaxProfile()->create();
    payDonation(submitDonation(['email' => 'nueva@example.com', 'wants_tax_receipt' => '1', 'rfc' => (string) $other->taxProfile?->rfc,
        'tax_name' => 'X', 'tax_regime' => (string) $other->taxProfile?->tax_regime->value, 'tax_postal_code' => '62000']));

    $new = Donor::query()->where('email', 'nueva@example.com')->sole();
    expect($new->id)->not->toBe($other->id)->and((string) $new->notes)->toContain('Posible duplicado');
});

it('proveedor caído: mensaje genérico, sin cargo doble al reintentar', function (): void {
    $token = submitDonation();
    fakeGateway()->willReturn(FakeScenario::ProviderUnavailable);

    post("/donar/pagar/{$token}")->assertRedirect("/donar/resumen/{$token}")->assertSessionHasErrors('payment');
    payDonation($token);

    expect(Payment::query()->count())->toBe(1)->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Succeeded);
});

it('Filament: la campaña muestra su enlace público; el panel sigue protegido', function (): void {
    $campaign = Campaign::factory()->create(['slug' => 'becas-publicas']);

    get(CampaignResource::getUrl('view', ['record' => $campaign]))->assertRedirect();
    actingAs(userWithRole(Role::FundraisingCoordinator));
    get(CampaignResource::getUrl('view', ['record' => $campaign]))
        ->assertOk()->assertSee('/donar/campana/becas-publicas')->assertSee('Abrir página pública');
});
