<?php

declare(strict_types=1);

use App\Actions\Donations\ConfirmDonation;
use App\Actions\Mail\SendTestEmail;
use App\Actions\Mail\UpdateMailSettings;
use App\Actions\Users\SetAccountingNoticePreference;
use App\Communications\ComposedMessage;
use App\Enums\AccountingNoticeStatus;
use App\Enums\AuditEvent;
use App\Enums\CommunicationStatus;
use App\Enums\DonationStatus;
use App\Enums\MailEncryption;
use App\Enums\ManualPaymentMethod;
use App\Enums\Role;
use App\Filament\Pages\MailSettings;
use App\Jobs\SendAccountingNotice;
use App\Jobs\SendCommunication;
use App\Mail\DonorMessage;
use App\Mail\Outgoing\OutgoingMailConfig;
use App\Mail\Outgoing\SmtpTransportFactory;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\MailSetting;
use App\Models\OrganizationSetting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\Support\RecordingSmtpFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Correo saliente administrable (docs/tecnico/correo-saliente.md). El SMTP
 * real se sustituye por RecordingSmtpFactory: la suite nunca usa la red.
 */

const SMTP_SECRET = 'Contraseña-Súper-Secreta-2026';

beforeEach(function (): void {
    app()->instance(SmtpTransportFactory::class, new RecordingSmtpFactory);
    OrganizationSetting::current()->forceFill(['legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA'])->save();
});

function smtp(): RecordingSmtpFactory
{
    $factory = app(SmtpTransportFactory::class);
    assert($factory instanceof RecordingSmtpFactory);

    return $factory;
}

function mailAdmin(): User
{
    return User::query()->where('role', Role::Administrator->value)->orderBy('id')->first() ?? userWithRole(Role::Administrator);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function smtpInput(array $overrides = []): array
{
    return [
        'enabled' => true, 'host' => 'smtp.ejemplo.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'crm@ejemplo.test',
        'password' => SMTP_SECRET, 'from_address' => 'donativos@ejemplo.test', 'from_name' => 'Fundación de Prueba',
        'reply_to_address' => 'contacto@ejemplo.test', 'reply_to_name' => 'Contacto', 'timeout' => 20, ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function configureSmtp(User $admin, array $overrides = []): MailSetting
{
    return app(UpdateMailSettings::class)->handle(smtpInput($overrides), $admin);
}

function confirmCashDonation(User $actor, string $email = 'donante@example.com'): Donation
{
    $donor = Donor::factory()->create(['email' => $email]);

    return app(ConfirmDonation::class)->handle(Donation::factory()->create(['donor_id' => $donor->id, 'manual_payment_method' => ManualPaymentMethod::Cash]), $actor);
}

it('solo el Administrador ve y cambia el correo saliente', function (): void {
    foreach ([Role::FundraisingCoordinator, Role::Accountant, Role::ReadOnly] as $role) {
        $user = userWithRole($role);
        expect(fn () => app(UpdateMailSettings::class)->handle(smtpInput(), $user))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SendTestEmail::class)->handle('a@example.com', $user))->toThrow(AuthorizationException::class);
        actingAs($user);
        get('/admin/correo-saliente')->assertForbidden();
    }

    actingAs(mailAdmin());
    get('/admin/correo-saliente')->assertOk()->assertSee('Correo saliente')->assertSee('SPF')->assertSee('DKIM')->assertSee('DMARC');
});

it('la contraseña se guarda cifrada y nunca aparece en la pantalla, la bitácora ni la serialización', function (): void {
    actingAs(mailAdmin());
    configureSmtp(mailAdmin());

    $raw = (string) DB::table('mail_settings')->value('password');
    expect($raw)->not->toContain(SMTP_SECRET)->and(Crypt::decryptString($raw))->toBe(SMTP_SECRET)
        ->and(MailSetting::current()->toArray())->not->toHaveKey('password');

    $html = get('/admin/correo-saliente')->assertOk()->getContent();
    expect(str_contains((string) $html, SMTP_SECRET))->toBeFalse()->and(str_contains((string) $html, $raw))->toBeFalse();
    Livewire::test(MailSettings::class)->assertSet('data.password', null)->assertDontSee(SMTP_SECRET);

    $audit = AuditLog::query()->where('auditable_type', 'mail_setting')->get();
    $serialized = $audit->map(fn (AuditLog $log): string => json_encode([$log->changed_fields, $log->old_values, $log->new_values], JSON_THROW_ON_ERROR))->implode(' ');
    expect(str_contains($serialized, SMTP_SECRET))->toBeFalse()->and(str_contains($serialized, $raw))->toBeFalse()
        ->and(str_contains($serialized, 'crm@ejemplo.test'))->toBeFalse()
        ->and($serialized)->toContain('reemplazada')->toContain('smtp.ejemplo.test');
});

it('contraseña vacía conserva la guardada; con texto la reemplaza; "eliminar" la quita explícitamente', function (): void {
    actingAs(mailAdmin());
    configureSmtp(mailAdmin());

    configureSmtp(mailAdmin(), ['password' => '', 'host' => 'smtp2.ejemplo.test']);
    expect(MailSetting::current()->password)->toBe(SMTP_SECRET);

    configureSmtp(mailAdmin(), ['password' => ' nueva clave ']);
    expect(MailSetting::current()->password)->toBe(' nueva clave ');

    expect(fn () => configureSmtp(mailAdmin(), ['password' => 'x', 'remove_password' => true]))->toThrow(ValidationException::class)
        ->and(fn () => configureSmtp(mailAdmin(), ['password' => null, 'remove_password' => true]))->toThrow(ValidationException::class, 'obligatoria');

    configureSmtp(mailAdmin(), ['password' => null, 'username' => null, 'remove_password' => true]);
    expect(MailSetting::current()->hasPassword())->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', 'mail_setting')->get()->pluck('new_values.smtp_password')->filter()->values()->all())
        ->toBe(['reemplazada', 'reemplazada', 'eliminada']);

    Livewire::test(MailSettings::class)->fillForm(['host' => 'smtp3.ejemplo.test'])->call('save')->assertHasNoFormErrors();
    expect(MailSetting::current()->host)->toBe('smtp3.ejemplo.test');
});

it('valida servidor, puerto, seguridad, correos y credenciales sin reglas de un proveedor', function (array $overrides, string $field): void {
    expect(fn () => configureSmtp(mailAdmin(), $overrides))->toThrow(ValidationException::class);
    try {
        configureSmtp(mailAdmin(), $overrides);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'puerto 0' => [['port' => 0], 'port'],
    'puerto 70000' => [['port' => 70000], 'port'],
    'servidor con esquema' => [['host' => 'smtp://smtp.ejemplo.test:587'], 'host'],
    'sin servidor habilitado' => [['host' => null], 'host'],
    'remitente inválido' => [['from_address' => 'no-es-correo'], 'from_address'],
    'reply-to inválido' => [['reply_to_address' => 'tampoco'], 'reply_to_address'],
    'seguridad desconocida' => [['encryption' => 'ssl3'], 'encryption'],
    'tiempo fuera de rango' => [['timeout' => 500], 'timeout'],
    'contraseña sin usuario' => [['username' => null], 'username'],
]);

it('acepta servidores internos o IP y relays sin autenticación (instalaciones corporativas)', function (): void {
    configureSmtp(mailAdmin(), ['host' => '10.0.0.25', 'port' => 25, 'encryption' => 'none', 'username' => null, 'password' => null]);

    expect(MailSetting::current()->isActive())->toBeTrue()->and(MailSetting::current()->hasPassword())->toBeFalse();
});

it('la configuración del panel tiene prioridad sobre MAIL_*; deshabilitada usa el entorno', function (): void {
    $mail = app(OutgoingMailConfig::class);
    expect($mail->currentMailer())->toBe('array');

    configureSmtp(mailAdmin());
    Mail::to('alguien@example.com')->send(new DonorMessage(new ComposedMessage('Hola', 'Texto', [], [], false, null, null)));
    expect($mail->currentMailer())->toBe(OutgoingMailConfig::MAILER)
        ->and(smtp()->sent)->toHaveCount(1)
        ->and(smtp()->sent[0]['host'])->toBe('smtp.ejemplo.test')
        ->and(smtp()->sent[0]['from'])->toBe('donativos@ejemplo.test')
        ->and(smtp()->sent[0]['reply_to'])->toBe(['contacto@ejemplo.test']);

    configureSmtp(mailAdmin(), ['enabled' => false, 'password' => '']);
    $arrayTransport = Mail::mailer('array')->getSymfonyTransport();
    assert($arrayTransport instanceof ArrayTransport);
    Mail::to('alguien@example.com')->send(new DonorMessage(new ComposedMessage('Hola 2', 'Texto', [], [], false, null, null)));
    expect($mail->currentMailer())->toBe('array')
        ->and(smtp()->sent)->toHaveCount(1)
        ->and($arrayTransport->messages()->count())->toBe(1);
});

it('el worker toma la configuración nueva antes de cada Job, sin reinicio', function (): void {
    $accountant = userWithRole(Role::Accountant);
    configureSmtp(mailAdmin());
    confirmCashDonation($accountant, 'uno@example.com');

    // Otro proceso (el panel) cambia la configuración: este proceso aún tiene la anterior en memoria.
    DB::table('mail_settings')->update(['host' => 'smtp-nuevo.ejemplo.test', 'version' => DB::raw('version + 1')]);
    confirmCashDonation($accountant, 'dos@example.com');

    $hosts = collect(smtp()->sent)->mapWithKeys(fn (array $mail): array => [$mail['to'][0] => $mail['host']]);
    expect($hosts['uno@example.com'])->toBe('smtp.ejemplo.test')->and($hosts['dos@example.com'])->toBe('smtp-nuevo.ejemplo.test');
});

it('agradecimiento con recibo adjunto y aviso a Contabilidad salen por el SMTP resuelto', function (): void {
    configureSmtp(mailAdmin());
    $accountant = userWithRole(Role::Accountant);
    app(SetAccountingNoticePreference::class)->handle($accountant, true, mailAdmin());

    $donation = confirmCashDonation($accountant);

    /** @var array<string, array{host: string|null, port: int|null, from: string, to: array<string>, reply_to: array<string>, subject: string, attachments: array<string>, body: string}> $byRecipient */
    $byRecipient = collect(smtp()->sent)->keyBy(fn (array $mail): string => $mail['to'][0])->all();
    expect($byRecipient['donante@example.com']['attachments'])->toBe(['Recibo-'.$donation->receipt?->folio.'.pdf'])
        ->and($byRecipient['donante@example.com']['from'])->toBe('donativos@ejemplo.test')
        ->and($byRecipient[$accountant->email]['subject'])->toContain('CFDI solicitado: NO')
        ->and($byRecipient[$accountant->email]['host'])->toBe('smtp.ejemplo.test');
});

it('un fallo SMTP no revierte el donativo ni el recibo; queda como fallo y el reintento envía', function (): void {
    configureSmtp(mailAdmin());
    $accountant = userWithRole(Role::Accountant);
    app(SetAccountingNoticePreference::class)->handle($accountant, true, mailAdmin());
    smtp()->failWith = new TransportException('Failed to authenticate on SMTP server with username "crm@ejemplo.test": 535 '.SMTP_SECRET);

    $donation = confirmCashDonation($accountant);

    $communication = Communication::query()->sole();
    $notice = $donation->accountingNotice()->sole();
    expect($donation->refresh()->status)->toBe(DonationStatus::Confirmed)
        ->and($donation->receipt)->not->toBeNull()
        ->and($communication->status)->toBe(CommunicationStatus::Failed)
        ->and(str_contains((string) $communication->last_error, SMTP_SECRET))->toBeFalse()
        ->and(str_contains((string) $communication->last_error, 'crm@ejemplo.test'))->toBeFalse()
        ->and($notice->status)->toBe(AccountingNoticeStatus::Failed);

    smtp()->failWith = null;
    dispatch_sync(new SendCommunication($communication->id));
    dispatch_sync(new SendAccountingNotice($notice->id));
    expect($communication->refresh()->status)->toBe(CommunicationStatus::Sent)
        ->and($notice->refresh()->status)->toBe(AccountingNoticeStatus::Sent)
        ->and(smtp()->sent)->toHaveCount(2);
});

it('correo de prueba: usa la configuración guardada (aunque esté deshabilitada), sin datos de donantes, y queda en la bitácora', function (): void {
    actingAs(mailAdmin());
    configureSmtp(mailAdmin(), ['enabled' => false]);

    app(SendTestEmail::class)->handle('prueba@example.com', mailAdmin());

    $sent = smtp()->sent[0];
    $settings = MailSetting::current();
    expect($sent['subject'])->toBe('Correo de prueba del CRM de FUNDACION DE PRUEBA')
        ->and($sent['to'])->toBe(['prueba@example.com'])->and($sent['from'])->toBe('donativos@ejemplo.test')
        ->and($sent['body'])->toContain('No contiene información de donantes')
        ->and(str_contains($sent['body'], '@example.com'))->toBeFalse()
        ->and($settings->last_successful_test_at)->not->toBeNull()->and($settings->last_successful_test_by_id)->toBe(mailAdmin()->id)
        ->and($settings->enabled)->toBeFalse();

    $log = AuditLog::query()->where('auditable_type', 'mail_setting')->where('event', AuditEvent::MailTest->value)->sole();
    expect($log->new_values)->toMatchArray(['result' => 'aceptado por el servidor', 'recipient' => 'p*****@example.com']);

    Livewire::test(MailSettings::class)->assertSee('Configurado')->assertSee(mailAdmin()->name)->assertSee('no garantiza que llegue');
});

it('error SMTP del correo de prueba: mensaje administrativo por tipo, sin credenciales ni cambios en la configuración', function (string $error, string $expected): void {
    actingAs(mailAdmin());
    configureSmtp(mailAdmin());
    $version = MailSetting::current()->version;
    smtp()->failWith = new TransportException($error);

    try {
        app(SendTestEmail::class)->handle('prueba@example.com', mailAdmin());
        throw new RuntimeException('Debió fallar');
    } catch (ValidationException $exception) {
        $message = implode(' ', $exception->errors()['recipient']);
        expect($message)->toContain($expected)
            ->and(str_contains($message, SMTP_SECRET))->toBeFalse()
            ->and(str_contains($message, 'crm@ejemplo.test'))->toBeFalse();
    }

    $settings = MailSetting::current();
    $log = AuditLog::query()->where('event', AuditEvent::MailTest->value)->sole();
    expect($settings->version)->toBe($version)->and($settings->last_successful_test_at)->toBeNull()
        ->and($log->new_values['result'] ?? null)->toBe('fallido')
        ->and(json_encode($log->new_values))->not->toContain(SMTP_SECRET);
})->with([
    'autenticación' => ['Failed to authenticate on SMTP server with username "crm@ejemplo.test" using "LOGIN": Expected response code "235" but got code "535", with message "535 5.7.8 Bad '.SMTP_SECRET.'".', 'usuario o la contraseña'],
    'conexión/DNS' => ['Connection could not be established with host "smtp.ejemplo.test:587": stream_socket_client(): php_network_getaddresses: getaddrinfo failed', 'No se pudo conectar'],
    'tiempo de espera' => ['Connection to "smtp.ejemplo.test:587" timed out.', 'no respondió a tiempo'],
    'TLS' => ['Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed', 'conexión segura'],
    'remitente' => ['Expected response code "250" but got code "553", with message "553 5.7.1 Sender address rejected: not owned by user".', 'remitente'],
    'destinatario' => ['Expected response code "250/251/252" but got code "550", with message "550 5.1.1 Recipient address rejected".', 'destinatario'],
    'otro' => ['Something unexpected', 'rechazó el envío'],
]);

it('limita los correos de prueba por Administrador', function (): void {
    configureSmtp(mailAdmin());
    RateLimiter::clear('mail-test:'.mailAdmin()->id);

    foreach (range(1, SendTestEmail::MAX_ATTEMPTS) as $i) {
        app(SendTestEmail::class)->handle("prueba{$i}@example.com", mailAdmin());
    }

    expect(fn () => app(SendTestEmail::class)->handle('otra@example.com', mailAdmin()))->toThrow(ValidationException::class, 'Demasiados')
        ->and(smtp()->sent)->toHaveCount(SendTestEmail::MAX_ATTEMPTS);
});

it('desde la pantalla: guardar y enviar la prueba muestran el resultado', function (): void {
    actingAs(mailAdmin());

    Livewire::test(MailSettings::class)->fillForm(smtpInput())->call('save')->assertHasNoFormErrors()->assertNotified('Correo saliente guardado');
    Livewire::test(MailSettings::class)->callAction(TestAction::make('sendTest'), ['recipient' => 'prueba@example.com'])
        ->assertNotified('Correo de prueba aceptado por el servidor SMTP');

    smtp()->failWith = new TransportException('Connection refused');
    Livewire::test(MailSettings::class)->callAction(TestAction::make('sendTest'), ['recipient' => 'prueba@example.com'])
        ->assertNotified('No se pudo enviar el correo de prueba');
});

it('la recuperación de contraseña sale por el SMTP resuelto y anula la contraseña temporal', function (): void {
    configureSmtp(mailAdmin());
    $user = userWithRole(Role::Accountant);

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $user->email])->call('request')->assertHasNoFormErrors();

    expect(collect(smtp()->sent)->where('to', [$user->email])->count())->toBe(1);

    $user->forceFill(['password_change_required_at' => now()])->save();
    event(new PasswordReset($user));
    expect($user->refresh()->password_change_required_at)->toBeNull();
});

it('el transporte real se arma con el estándar de Symfony sin conectarse', function (MailEncryption $encryption, bool $implicitTls): void {
    $settings = new MailSetting(['host' => 'smtp.ejemplo.test', 'port' => 465, 'encryption' => $encryption, 'username' => 'u', 'password' => 'p', 'timeout' => 15]);

    $transport = (new SmtpTransportFactory)->make($settings);

    expect($transport)->toBeInstanceOf(EsmtpTransport::class);
    assert($transport instanceof EsmtpTransport);
    $stream = $transport->getStream();
    assert($stream instanceof SocketStream);
    expect($stream->getHost())->toBe('smtp.ejemplo.test')->and($stream->getPort())->toBe(465)
        ->and($stream->isTLS())->toBe($implicitTls)->and($stream->getTimeout())->toBe(15.0)
        ->and($transport->getUsername())->toBe('u')->and($transport->getPassword())->toBe('p');
})->with([
    'STARTTLS' => [MailEncryption::StartTls, false],
    'SSL/TLS' => [MailEncryption::Tls, true],
    'ninguno' => [MailEncryption::None, false],
]);
