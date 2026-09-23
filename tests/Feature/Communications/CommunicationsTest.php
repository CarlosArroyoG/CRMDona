<?php

declare(strict_types=1);

use App\Actions\Cfdi\RequestDonationCfdi;
use App\Actions\Communications\IssueDonationReceipt;
use App\Actions\Communications\QueueDonationThankYou;
use App\Actions\Communications\ResendCommunication;
use App\Actions\Communications\UpdateMessageTemplate;
use App\Actions\Donations\ConfirmDonation;
use App\Communications\MessageComposer;
use App\Enums\AuditSource;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Enums\DonationStatus;
use App\Enums\ManualPaymentMethod;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Jobs\SendBirthdayGreetings;
use App\Jobs\SendCommunication;
use App\Jobs\StampCfdi;
use App\Mail\DonorMessage;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\MessageTemplate;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

beforeEach(function (): void {
    Mail::fake();
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15',
    ])->save();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function pendingDonation(array $attributes = [], bool $taxProfile = true): Donation
{
    $donor = ($taxProfile ? Donor::factory()->withTaxProfile() : Donor::factory())->create(['email' => 'maria.lopez@example.com']);

    return Donation::factory()->create(['donor_id' => $donor->id, 'manual_payment_method' => ManualPaymentMethod::Cash, 'amount' => '1500.00', ...$attributes]);
}

function confirm(Donation $donation): Donation
{
    return app(ConfirmDonation::class)->handle($donation, userWithRole(Role::Accountant));
}

/**
 * @return list<DonorMessage>
 */
function sentMessages(): array
{
    /** @var list<DonorMessage> $messages */
    $messages = Mail::sent(DonorMessage::class)->values()->all();

    return $messages;
}

it('al confirmar: emite el recibo simple y envía un solo agradecimiento aunque se repita el evento', function (): void {
    $donation = confirm(pendingDonation(taxProfile: false));

    app(QueueDonationThankYou::class)->handle($donation);
    $communication = Communication::query()->sole();
    dispatch_sync(new SendCommunication($communication->id));

    expect($communication->refresh()->status)->toBe(CommunicationStatus::Sent)
        ->and($communication->kind)->toBe(CommunicationKind::ThankYou)
        ->and($communication->recipient)->toBe('m**********@example.com')
        ->and($communication->attachments)->toBe(['Recibo-R-'.str_pad((string) $donation->receipt?->id, 6, '0', STR_PAD_LEFT).'.pdf'])
        ->and(sentMessages())->toHaveCount(1)
        ->and(DonationReceipt::query()->count())->toBe(1);
    Mail::assertSent(DonorMessage::class, fn (DonorMessage $mail): bool => $mail->hasTo('maria.lopez@example.com')
        && in_array(IssueDonationReceipt::DISCLAIMER, $mail->message->notices, true));
});

it('recibo simple: folio interno, datos de la organización e importe; dice que no es factura y no expone RFC ni correo del donante', function (): void {
    $donation = confirm(pendingDonation());
    $receipt = app(IssueDonationReceipt::class)->handle($donation);

    expect($receipt->folio)->toBe(DonationReceipt::folioFor($receipt->id))
        ->and(app(IssueDonationReceipt::class)->handle($donation)->id)->toBe($receipt->id);

    $pdf = (string) Storage::disk('local')->get((string) $receipt->pdf_path);
    expect($pdf)->toStartWith('%PDF-1.4')->toContain('RECIBO DE DONATIVO')->toContain('No es una factura')
        ->toContain((string) $receipt->folio)->toContain('FUNDACION DE PRUEBA')->toContain('$1,500.00 MXN')
        ->and(str_contains($pdf, 'maria.lopez@example.com'))->toBeFalse()
        ->and(str_contains($pdf, (string) $donation->donor->taxProfile?->rfc))->toBeFalse()
        ->and(Storage::disk('public')->exists((string) $receipt->pdf_path))->toBeFalse()
        ->and(fn () => app(IssueDonationReceipt::class)->handle(pendingDonation()))->toThrow(ValidationException::class);
});

it('sin CFDI todavía: el agradecimiento sale y avisa que el CFDI llegará aparte, sin afirmar validez fiscal', function (): void {
    confirm(pendingDonation());

    $mail = sentMessages()[0];
    expect($mail->message->attachmentNames())->toHaveCount(1)
        ->and($mail->message->notices)->toContain('Tu comprobante fiscal (CFDI) te llegará en un correo aparte en cuanto esté listo.');
});

it('público en general: el agradecimiento no promete un CFDI', function (): void {
    confirm(pendingDonation(taxProfile: false));

    expect(implode(' ', sentMessages()[0]->message->notices))->not->toContain('CFDI) te llegará');
});

it('CFDI posterior: al timbrarse se envía una vez, sin repetir el agradecimiento', function (): void {
    $donation = confirm(pendingDonation());
    $admin = userWithRole(Role::Administrator);

    $cfdi = app(RequestDonationCfdi::class)->handle($donation, $admin)->refresh();
    dispatch_sync(new StampCfdi($cfdi->id));

    $kinds = Communication::query()->orderBy('id')->pluck('kind')->map->value->all();
    expect($cfdi->status->value)->toBe('stamped')
        ->and($kinds)->toBe(['thank_you', 'cfdi'])
        ->and(sentMessages())->toHaveCount(2)
        ->and(sentMessages()[1]->message->attachmentNames())->toBe(['CFDI-'.strtoupper((string) $cfdi->uuid).'.xml', 'CFDI-'.strtoupper((string) $cfdi->uuid).'.pdf']);
});

it('con CFDI ya timbrado cuando sale el agradecimiento: lo adjunta y no manda otro correo de CFDI', function (): void {
    config(['cfdi.auto_issue' => true, 'queue.default' => 'database', 'communications.thank_you_delay_seconds' => 60]);
    $donation = confirm(pendingDonation());

    runQueueWorker(); // Timbra; el agradecimiento aún espera.
    travelTo(now()->addMinutes(2));
    runQueueWorker();

    $communication = Communication::query()->sole();
    expect($communication->kind)->toBe(CommunicationKind::ThankYou)
        ->and($communication->status)->toBe(CommunicationStatus::Sent)
        ->and($communication->cfdi_id)->toBe($donation->activeCfdi()?->id)
        ->and($communication->attachments)->toHaveCount(3)
        ->and(sentMessages())->toHaveCount(1);
});

it('fallo del servidor de correo: queda fallido con el error y el reintento lo envía una sola vez', function (): void {
    $donation = pendingDonation(taxProfile: false);
    $manager = new MailManager(app());
    $manager->extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('SMTP 421 servicio no disponible');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
    config(['mail.default' => 'failing', 'mail.mailers.failing' => ['transport' => 'failing']]);
    Mail::swap($manager);

    confirm($donation);
    $communication = Communication::query()->sole();
    expect($communication->status)->toBe(CommunicationStatus::Failed)->and($communication->last_error)->toContain('SMTP 421');

    Mail::fake();
    dispatch_sync(new SendCommunication($communication->id));
    dispatch_sync(new SendCommunication($communication->id));

    expect($communication->refresh()->status)->toBe(CommunicationStatus::Sent)->and($communication->attempts)->toBe(2)
        ->and(sentMessages())->toHaveCount(1);
});

it('plantilla inválida: se usa el texto predeterminado y se marca; la edición también la rechaza', function (): void {
    MessageTemplate::ensureDefaults();
    MessageTemplate::query()->where('kind', 'thank_you')->update(['body' => 'Hola {{ variable_inexistente }} {{ nombre']);

    confirm(pendingDonation(taxProfile: false));

    expect(Communication::query()->sole()->used_fallback_template)->toBeTrue()
        ->and(sentMessages()[0]->message->body)->toContain('Gracias por tu donativo de $1,500.00 MXN');

    $template = MessageTemplate::query()->where('kind', 'birthday')->firstOrFail();
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    expect(fn () => app(UpdateMessageTemplate::class)->handle($template, ['subject' => 'Hola', 'body' => '{{ importe }}'], $coordinator))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateMessageTemplate::class)->handle($template, ['subject' => 'Hola', 'body' => '{{ nombre'], $coordinator))
        ->toThrow(ValidationException::class)
        ->and(app(UpdateMessageTemplate::class)->handle($template, ['subject' => 'Felicidades, {{ nombre }}', 'body' => 'Un abrazo'], $coordinator)->updated_by_id)
        ->toBe($coordinator->id)
        ->and(fn () => app(UpdateMessageTemplate::class)->handle($template, ['subject' => 'x', 'body' => 'y'], userWithRole(Role::Accountant)))
        ->toThrow(AuthorizationException::class);
});

it('vista previa con datos de ejemplo e indicación de error', function (): void {
    $composer = app(MessageComposer::class);

    expect($composer->preview(CommunicationKind::ThankYou, 'Gracias {{ nombre }}', 'Donativo de {{ importe }}'))
        ->toMatchArray(['subject' => 'Gracias María', 'body' => 'Donativo de $1,500.00 MXN', 'error' => null])
        ->and($composer->preview(CommunicationKind::Birthday, 'x', '{{ importe }}')['error'])->toContain('importe');
});

it('cumpleaños: solo con consentimiento, no archivados y con correo; una vez por día aunque el job se repita', function (): void {
    travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'America/Mexico_City'));
    $birthday = ['birth_date' => '1980-10-05', 'accepts_communications' => true];
    $ok = Donor::factory()->create($birthday);
    Donor::factory()->create([...$birthday, 'accepts_communications' => false]);
    Donor::factory()->archived()->create($birthday);
    Donor::factory()->create([...$birthday, 'email' => null]);
    Donor::factory()->create([...$birthday, 'birth_date' => '1980-10-06']);

    dispatch_sync(new SendBirthdayGreetings);
    dispatch_sync(new SendBirthdayGreetings);

    expect(Communication::query()->pluck('donor_id')->all())->toBe([$ok->id])
        ->and(sentMessages())->toHaveCount(1)
        ->and(sentMessages()[0]->message->unsubscribeUrl)->toContain('/comunicaciones/baja/');
});

it('cumpleaños del 29 de febrero se felicita el 28 en años no bisiestos; apagado no envía', function (): void {
    travelTo(CarbonImmutable::parse('2027-02-28 09:00', 'America/Mexico_City'));
    Donor::factory()->create(['birth_date' => '1992-02-29', 'accepts_communications' => true]);

    OrganizationSetting::current()->forceFill(['birthday_emails_enabled' => false])->save();
    dispatch_sync(new SendBirthdayGreetings);
    expect(Communication::query()->count())->toBe(0);

    OrganizationSetting::current()->forceFill(['birthday_emails_enabled' => true])->save();
    dispatch_sync(new SendBirthdayGreetings);
    expect(Communication::query()->count())->toBe(1);
});

it('el scheduler programa la felicitación a las 09:00 de México', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->description, SendBirthdayGreetings::class));

    expect($event)->toBeInstanceOf(Event::class);
    /** @var Event $event */
    expect($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone instanceof DateTimeZone ? $event->timezone->getName() : $event->timezone)->toBe('America/Mexico_City');
});

it('baja segura: token aleatorio, sin sesión, no muestra datos, audita sin PII y no afecta a otro donante', function (): void {
    $donor = Donor::factory()->create(['accepts_communications' => true, 'first_name' => 'Rigoberta']);
    $other = Donor::factory()->create(['accepts_communications' => true]);
    $token = $donor->communicationsToken();

    get("/comunicaciones/baja/{$token}")->assertOk()->assertSee('Darme de baja')->assertDontSee('Rigoberta');
    post("/comunicaciones/baja/{$token}")->assertOk()->assertSee('Listo');

    expect($donor->refresh()->accepts_communications)->toBeFalse()
        ->and($other->refresh()->accepts_communications)->toBeTrue();
    $log = AuditLog::query()->where('auditable_type', 'donor')->where('auditable_id', $donor->id)->latest('id')->firstOrFail();
    expect($log->source)->toBe(AuditSource::Donor)->and($log->user_id)->toBeNull()
        ->and(str_contains((string) json_encode($log->new_values), 'Rigoberta'))->toBeFalse()
        ->and(str_contains((string) json_encode($log->new_values), (string) $donor->email))->toBeFalse();

    $tampered = substr($token, 0, -1).($token[63] === 'a' ? 'b' : 'a');
    post("/comunicaciones/baja/{$tampered}")->assertNotFound();
    get('/comunicaciones/baja/'.$other->id)->assertNotFound();
    expect($other->refresh()->accepts_communications)->toBeTrue();
});

it('reenvío: crea otro registro con quien lo pidió; la felicitación no se reenvía', function (): void {
    confirm(pendingDonation(taxProfile: false));
    $original = Communication::query()->sole();
    $coordinator = userWithRole(Role::FundraisingCoordinator);

    $copy = app(ResendCommunication::class)->handle($original, $coordinator)->refresh();

    expect($copy->id)->not->toBe($original->id)->and($copy->requested_by_id)->toBe($coordinator->id)
        ->and($copy->status)->toBe(CommunicationStatus::Sent)->and(sentMessages())->toHaveCount(2)
        ->and(fn () => app(ResendCommunication::class)->handle($original, userWithRole(Role::ReadOnly)))->toThrow(AuthorizationException::class);
});

it('sin correo: queda "No enviado" con el motivo', function (): void {
    $donation = pendingDonation(taxProfile: false);
    $donation->donor->forceFill(['email' => null])->save();

    confirm($donation);

    expect(Communication::query()->sole()->status)->toBe(CommunicationStatus::Skipped)->and(sentMessages())->toBeEmpty();
});

it('permisos y privacidad de archivos: recibo y pantallas según el rol', function (Role $role, int $receipt, int $history, int $templates): void {
    $donation = confirm(pendingDonation(taxProfile: false));
    $receiptModel = DonationReceipt::query()->sole();
    actingAs(userWithRole($role));

    get(route('receipts.file', $receiptModel))->assertStatus($receipt);
    get('/admin/communications')->assertStatus($history);
    get('/admin/message-templates')->assertStatus($templates);
    expect($donation->status)->toBe(DonationStatus::Confirmed);
})->with([
    'Administrador' => [Role::Administrator, 200, 200, 200],
    'Coordinador' => [Role::FundraisingCoordinator, 200, 200, 200],
    'Contador' => [Role::Accountant, 200, 200, 403],
    'Solo lectura' => [Role::ReadOnly, 403, 403, 403],
]);

it('el recibo no se descarga sin sesión', function (): void {
    confirm(pendingDonation(taxProfile: false));

    get(route('receipts.file', DonationReceipt::query()->sole()))->assertRedirect();
});

it('la pantalla de edición de plantillas carga para el Coordinador y muestra las variables', function (): void {
    MessageTemplate::ensureDefaults();
    actingAs(userWithRole(Role::FundraisingCoordinator));

    get(MessageTemplateResource::getUrl('edit', ['record' => MessageTemplate::query()->where('kind', 'thank_you')->firstOrFail()]))
        ->assertOk()->assertSee('folio_recibo');
});
