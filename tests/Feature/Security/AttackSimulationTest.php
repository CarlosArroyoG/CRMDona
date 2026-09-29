<?php

declare(strict_types=1);

use App\Actions\Communications\SaveBulkMessage;
use App\Actions\Donors\ImportDonorRow;
use App\Communications\BulkAudience;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withServerVariables;
use function Pest\Laravel\withSession;

/*
 * Ataques simulados contra el stack real (HTTP, Livewire y PostgreSQL):
 * inyección SQL con variantes de mayúsculas, comentarios y Unicode,
 * suplantación de IP con X-Forwarded-For y ráfagas de peticiones.
 */

const SQL_INJECTION_PAYLOADS = [
    "' OR '1'='1",
    "' oR 1=1 --",
    "' Or 1=1#",
    "'; DROP TABLE donors; --",
    '" OR ""="',
    "1' UNION SELECT password FROM users --",
    "1' uNiOn SeLeCt email, password FrOm users--",
    "admin'/**/OR/**/1=1--",
    "'||(SELECT pg_sleep(3))||'",
    "'; SELECT pg_sleep(3); --",
    "%' AND 1=1 AND '%'='",
    '％27 OR 1=1',
    "\\' OR 1=1 --",
    '$$ OR 1=1 $$',
    "' OR 1=1 LIMIT 1 OFFSET 0 --",
    '%',
    '_',
];

beforeEach(function (): void {
    Mail::fake();
    config(['donations.public.min_seconds_to_submit' => 0, 'donations.public.suggested_amounts' => ['200', '500']]);
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
function attackDonationForm(array $overrides = []): array
{
    return [
        'frequency' => 'one_time', 'amount' => '500', 'donor_type' => 'individual',
        'first_name' => 'Lucía', 'last_name' => 'Hernández', 'email' => 'lucia.hernandez@example.com',
        'privacy_accepted' => '1', ...$overrides,
    ];
}

it('la búsqueda del panel trata la inyección SQL como texto (mayúsculas, comentarios, Unicode, pg_sleep)', function (): void {
    actingAs(userWithRole(Role::Administrator));
    Donor::factory()->count(3)->create();
    $donors = Donor::query()->count();
    $users = User::query()->count();
    // Calentamiento: el primer render de Livewire es lento y no debe confundirse con pg_sleep.
    Livewire::test(ListDonors::class)->searchTable('calentamiento');

    foreach (SQL_INJECTION_PAYLOADS as $payload) {
        $started = microtime(true);

        Livewire::test(ListDonors::class)->searchTable($payload)->assertCountTableRecords(0);

        // pg_sleep(3) nunca se ejecuta: la consulta responde de inmediato.
        expect(microtime(true) - $started)->toBeLessThan(2.5, "Lento con: {$payload}");
    }

    expect(Donor::query()->count())->toBe($donors)
        ->and(User::query()->count())->toBe($users)
        ->and(DB::table('donors')->exists())->toBeTrue();
});

it('la página pública guarda la inyección SQL como texto literal y no altera la base', function (string $payload): void {
    $users = User::query()->count();

    $response = withSession(['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()])
        ->post('/donar', attackDonationForm(['first_name' => $payload, 'last_name' => $payload, 'email' => 'ataque@example.com']));
    $response->assertRedirect();
    preg_match('#/donar/resumen/([A-Za-z0-9]{40})#', (string) $response->headers->get('Location'), $match);
    post('/donar/pagar/'.($match[1] ?? 'sin-token'), ['fake_scenario' => 'success']);

    expect(Donor::query()->where('email', 'ataque@example.com')->value('first_name'))->toBe(trim($payload))
        ->and(User::query()->count())->toBe($users);
})->with([
    'comillas' => ["' OR '1'='1"],
    'mayúsculas y minúsculas' => ["1' uNiOn SeLeCt email, password FrOm users--"],
    'borrado' => ["'; DROP TABLE donors; --"],
    'comentarios en línea' => ["admin'/**/OR/**/1=1--"],
    'Unicode de ancho completo' => ['％27 OR 1=1'],
]);

it('rechaza la inyección en parámetros de la URL y en el correo', function (): void {
    get("/donar/campana/' OR 1=1 --")->assertNotFound();
    get("/donar/resumen/' UNION SELECT 1 --")->assertNotFound();
    get("/donar/estado/1' OR '1'='1")->assertNotFound();
    get("/comunicaciones/baja/' OR 1=1 --")->assertNotFound();
    post("/webhooks/payments/stripe' OR 1=1 --")->assertNotFound();
    post('/webhooks/payments/STRIPE')->assertNotFound();

    withSession(['public_donation_form_opened_at' => now()->subMinute()->getTimestamp()])
        ->post('/donar', attackDonationForm(['email' => "x@example.com' OR '1'='1"]))
        ->assertSessionHasErrors('email');
});

it('la carga CSV y los filtros del envío masivo no aceptan SQL', function (): void {
    $donor = app(ImportDonorRow::class)->handle(
        ['first_name' => "Robert'); DROP TABLE donors;--", 'last_name' => "O'Brien", 'tags' => "x' OR '1'='1"],
        userWithRole(Role::FundraisingCoordinator),
        false,
    );
    expect($donor->first_name)->toBe("Robert'); DROP TABLE donors;--")
        ->and($donor->tags()->value('name'))->toBe("x' OR '1'='1");

    expect(fn () => app(SaveBulkMessage::class)->handle(null, [
        'subject' => 'Hola', 'body' => 'Texto', 'tag_ids' => ['1 OR 1=1'],
    ], userWithRole(Role::Administrator)))->toThrow(ValidationException::class);

    // Aunque llegara directo a la audiencia, los valores no numéricos o inválidos se descartan.
    $audience = BulkAudience::fromArray(['tag_ids' => ['1 OR 1=1'], 'donor_type' => "individual' OR '1'='1", 'donated_from' => "2026-01-01' OR '1'='1"]);
    expect($audience->tagIds)->toBe([])
        ->and($audience->donorType)->toBeNull()
        ->and($audience->donatedFrom)->toBe('2026-01-01')
        ->and($audience->recipients()->count())->toBeInt();
});

it('suplantar la IP con X-Forwarded-For no evade el límite de envíos (cliente directo)', function (): void {
    config(['donations.public.rate_limit_per_minute' => 3]);

    foreach (range(1, 3) as $i) {
        withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}"])
            ->post('/donar', [])->assertStatus(302);
    }

    withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeaders(['X-Forwarded-For' => '198.51.100.99'])
        ->post('/donar', [])->assertStatus(429);
});

it('suplantar la IP a través del proxy de Coolify tampoco evade el límite', function (): void {
    config(['donations.public.rate_limit_per_minute' => 3]);

    // El atacante inventa la primera IP; el proxy agrega la real al final.
    foreach (range(1, 3) as $i) {
        withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}, 203.0.113.9"])
            ->post('/donar', [])->assertStatus(302);
    }

    withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])->withHeaders(['X-Forwarded-For' => '198.51.100.99, 203.0.113.9'])
        ->post('/donar', [])->assertStatus(429);

    // Otro visitante real detrás del mismo proxy no queda bloqueado.
    withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])->withHeaders(['X-Forwarded-For' => '203.0.113.50'])
        ->post('/donar', [])->assertStatus(302);
});

it('una ráfaga de visitas a las páginas públicas se corta por IP', function (): void {
    config(['donations.public.page_rate_limit_per_minute' => 5]);

    foreach (range(1, 5) as $ignored) {
        withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/donar')->assertOk();
    }
    withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/donar')->assertStatus(429);
    withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get('/donar/estado/'.str_repeat('a', 40))->assertStatus(429);

    withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])->get('/donar')->assertOk();
});

it('los webhooks con firma falsa se rechazan y tienen límite por IP', function (): void {
    foreach (range(1, 3) as $ignored) {
        withHeaders(['Stripe-Signature' => 't=1,v1=falsa'])->postJson('/webhooks/payments/fake', ['id' => 'evt_falso'])->assertStatus(400);
    }

    expect(DB::table('webhook_events')->count())->toBe(0);
});

it('fuerza bruta al login: tras 5 intentos fallidos ni la contraseña correcta entra', function (): void {
    Filament::setCurrentPanel('admin');
    User::factory()->withRole(Role::Administrator)->create(['email' => 'ana.admin@example.com', 'password' => 'Correcta-2026-segura']);

    foreach (range(1, 5) as $i) {
        Livewire::test(Login::class)->set('data.email', 'ana.admin@example.com')->set('data.password', "Mala-{$i}-2026")->call('authenticate');
    }

    Livewire::test(Login::class)->set('data.email', 'ana.admin@example.com')->set('data.password', 'Correcta-2026-segura')
        ->call('authenticate')->assertNotified();

    assertGuest();
});
