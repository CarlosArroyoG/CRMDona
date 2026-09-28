<?php

declare(strict_types=1);

use App\Actions\Communications\DeleteBulkMessage;
use App\Actions\Communications\QueueCommunication;
use App\Actions\Communications\SaveBulkMessage;
use App\Actions\Communications\SendBulkMessage;
use App\Actions\Communications\SendBulkMessageTest;
use App\Actions\Communications\StopBulkMessage;
use App\Communications\BulkAudience;
use App\Communications\MessageComposer;
use App\Enums\AuditEvent;
use App\Enums\BulkMessageStatus;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Enums\DonationStatus;
use App\Enums\Role;
use App\Filament\Resources\BulkMessages\BulkMessageResource;
use App\Filament\Resources\BulkMessages\Pages\CreateBulkMessage;
use App\Filament\Resources\BulkMessages\Pages\ListBulkMessages;
use App\Filament\Resources\BulkMessages\Pages\ViewBulkMessage;
use App\Jobs\PrepareBulkMessage;
use App\Jobs\SendCommunication;
use App\Mail\DonorMessage;
use App\Models\AuditLog;
use App\Models\BulkMessage;
use App\Models\Campaign;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Program;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    Mail::fake();
});

/**
 * @param  array<string, mixed>  $input
 */
function bulkDraft(array $input = [], ?User $actor = null): BulkMessage
{
    return app(SaveBulkMessage::class)->handle(null, [
        'subject' => 'Noticias de {{ organizacion }}',
        'body' => "Hola, {{ nombre }}:\n\nTe compartimos lo que logramos este mes.",
        ...$input,
    ], $actor ?? userWithRole(Role::FundraisingCoordinator));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function consentingDonor(array $attributes = []): Donor
{
    return Donor::factory()->create(['accepts_communications' => true, ...$attributes]);
}

function testedAndSent(BulkMessage $message, User $actor): BulkMessage
{
    app(SendBulkMessageTest::class)->handle($message, $actor);

    return app(SendBulkMessage::class)->handle($message->refresh(), $actor);
}

it('solo incluye donantes activos, con correo y que aceptan comunicaciones', function (): void {
    $si = consentingDonor();
    consentingDonor(['email' => null]);
    Donor::factory()->create(['accepts_communications' => false]);
    consentingDonor(['archived_at' => now()]);

    $audience = new BulkAudience;

    expect($audience->recipients()->pluck('id')->all())->toBe([$si->id])
        ->and($audience->summary())->toBe(['matching' => 3, 'recipients' => 1, 'without_email' => 1, 'without_consent' => 1]);
});

it('combina los filtros de tipo, etiquetas, campaña, programa y fechas', function (): void {
    $padrino = Tag::query()->create(['name' => 'Padrino']);
    $becas = Program::factory()->create();
    $campana = Campaign::factory()->create(['program_id' => $becas->id]);

    $conEtiqueta = consentingDonor();
    $conEtiqueta->tags()->attach($padrino);
    $empresa = Donor::factory()->organization()->create(['accepts_communications' => true]);
    $empresa->tags()->attach($padrino);

    $donoCampana = consentingDonor();
    Donation::factory()->confirmed()->create(['donor_id' => $donoCampana->id, 'campaign_id' => $campana->id, 'received_on' => '2026-05-10']);
    $donoPendiente = consentingDonor();
    Donation::factory()->create(['donor_id' => $donoPendiente->id, 'campaign_id' => $campana->id, 'received_on' => '2026-05-10', 'status' => DonationStatus::Pending]);
    $donoAntes = consentingDonor();
    Donation::factory()->confirmed()->create(['donor_id' => $donoAntes->id, 'program_id' => $becas->id, 'received_on' => '2025-01-10']);

    $ids = fn (array $filters): array => BulkAudience::fromArray($filters)->recipients()->orderBy('id')->pluck('id')->all();

    expect($ids(['tag_ids' => [$padrino->id]]))->toBe([$conEtiqueta->id, $empresa->id])
        ->and($ids(['tag_ids' => [$padrino->id], 'donor_type' => 'individual']))->toBe([$conEtiqueta->id])
        ->and($ids(['campaign_ids' => [$campana->id]]))->toBe([$donoCampana->id])
        // El programa cuenta directo o a través de su campaña.
        ->and($ids(['program_ids' => [$becas->id]]))->toBe([$donoCampana->id, $donoAntes->id])
        ->and($ids(['program_ids' => [$becas->id], 'donated_from' => '2026-01-01', 'donated_until' => '2026-12-31']))->toBe([$donoCampana->id]);
});

it('valida el texto y las variables del borrador', function (): void {
    expect(fn () => bulkDraft(['body' => 'Hola {{ importe }}']))->toThrow(ValidationException::class, 'Variables no disponibles: importe')
        ->and(fn () => bulkDraft(['subject' => 'Hola {{ nombre']))->toThrow(ValidationException::class, 'sin cerrar')
        ->and(fn () => bulkDraft(['donated_from' => '2026-05-01', 'donated_until' => '2026-04-01']))->toThrow(ValidationException::class);
});

it('solo Administrador y Coordinador preparan envíos masivos', function (): void {
    expect(fn () => bulkDraft(actor: userWithRole(Role::Accountant)))->toThrow(AuthorizationException::class)
        ->and(fn () => bulkDraft(actor: userWithRole(Role::ReadOnly)))->toThrow(AuthorizationException::class);

    $message = bulkDraft();
    expect(fn () => app(SendBulkMessage::class)->handle($message, userWithRole(Role::Accountant)))->toThrow(AuthorizationException::class);
});

it('exige la prueba antes de enviar y la invalida si cambia el texto', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    consentingDonor();
    $message = bulkDraft(actor: $actor);

    expect(fn () => app(SendBulkMessage::class)->handle($message, $actor))->toThrow(ValidationException::class, 'correo de prueba');

    app(SendBulkMessageTest::class)->handle($message, $actor);
    $test = Mail::sent(DonorMessage::class)->sole();
    expect($test->hasTo($actor->email))->toBeTrue()
        ->and($test->message->subject)->toStartWith('[Prueba] Noticias de')
        ->and($test->message->body)->toContain('Hola, María:')
        ->and($message->refresh()->tested_at)->not->toBeNull()
        ->and(AuditLog::query()->where('auditable_type', 'bulk_message')->where('event', AuditEvent::MailTest)->exists())->toBeTrue();

    // Cambiar solo la audiencia conserva la prueba; cambiar el texto la invalida.
    app(SaveBulkMessage::class)->handle($message, ['subject' => $message->subject, 'body' => $message->body, 'donor_type' => 'individual'], $actor);
    expect($message->refresh()->tested_at)->not->toBeNull();
    app(SaveBulkMessage::class)->handle($message, ['subject' => $message->subject, 'body' => 'Otro texto, {{ nombre }}.'], $actor);
    expect($message->refresh()->tested_at)->toBeNull();
});

it('no envía si nadie cumple los filtros', function (): void {
    $actor = userWithRole(Role::Administrator);
    Donor::factory()->create(['accepts_communications' => false]);
    $message = bulkDraft(actor: $actor);
    app(SendBulkMessageTest::class)->handle($message, $actor);

    expect(fn () => app(SendBulkMessage::class)->handle($message->refresh(), $actor))->toThrow(ValidationException::class, 'Ningún donante');
});

it('envía un correo personalizado con enlace de baja a cada destinatario', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $ana = consentingDonor(['first_name' => 'Ana', 'email' => 'ana@example.com']);
    $luis = consentingDonor(['first_name' => 'Luis', 'email' => 'luis@example.com']);
    $sinConsentimiento = Donor::factory()->create(['email' => 'no@example.com']);

    $message = testedAndSent(bulkDraft(actor: $actor), $actor)->refresh();

    $sent = Mail::sent(DonorMessage::class)->filter(fn (DonorMessage $mail): bool => ! $mail->hasTo($actor->email))->values();

    expect($message->status)->toBe(BulkMessageStatus::Sent)
        ->and($message->recipients_count)->toBe(2)
        ->and($message->sent_by_id)->toBe($actor->id)
        ->and($sent)->toHaveCount(2)
        ->and($sent->first(fn (DonorMessage $mail): bool => $mail->hasTo('ana@example.com'))?->message->body)->toContain('Hola, Ana:')
        ->and($sent->first(fn (DonorMessage $mail): bool => $mail->hasTo('luis@example.com'))?->message->unsubscribeUrl)->toContain('/comunicaciones/baja/')
        ->and(Mail::sent(DonorMessage::class, fn (DonorMessage $mail): bool => $mail->hasTo('no@example.com')))->toHaveCount(0);

    $communications = Communication::query()->where('bulk_message_id', $message->id)->get();
    expect($communications)->toHaveCount(2)
        ->and($communications->pluck('kind')->unique()->all())->toBe([CommunicationKind::BulkMessage])
        ->and($communications->pluck('status')->unique()->all())->toBe([CommunicationStatus::Sent])
        ->and($communications->pluck('requested_by_id')->unique()->all())->toBe([$actor->id])
        ->and($communications->pluck('donor_id')->sort()->values()->all())->toBe([$ana->id, $luis->id])
        ->and($sinConsentimiento->id)->not->toBeIn($communications->pluck('donor_id')->all())
        ->and(AuditLog::query()->where('auditable_type', 'bulk_message')->where('event', AuditEvent::BulkSent)->exists())->toBeTrue();
});

it('no duplica correos si el Job se repite', function (): void {
    $actor = userWithRole(Role::Administrator);
    consentingDonor();
    consentingDonor();
    $message = bulkDraft(actor: $actor);
    app(SendBulkMessageTest::class)->handle($message, $actor);

    Queue::fake();
    app(SendBulkMessage::class)->handle($message->refresh(), $actor);
    (new PrepareBulkMessage($message->id))->handle(app(QueueCommunication::class));
    $message->forceFill(['status' => BulkMessageStatus::Preparing])->save();
    (new PrepareBulkMessage($message->id))->handle(app(QueueCommunication::class));

    expect(Communication::query()->where('bulk_message_id', $message->id)->count())->toBe(2)
        ->and($message->refresh()->status)->toBe(BulkMessageStatus::Sent);
    Queue::assertPushed(SendCommunication::class, 2);
});

it('reparte los correos en la cola según el límite por minuto', function (): void {
    config(['communications.bulk.per_minute' => 2]);
    $actor = userWithRole(Role::Administrator);
    foreach (range(1, 5) as $ignored) {
        consentingDonor();
    }
    $message = bulkDraft(actor: $actor);
    app(SendBulkMessageTest::class)->handle($message, $actor);

    Queue::fake();
    app(SendBulkMessage::class)->handle($message->refresh(), $actor);
    (new PrepareBulkMessage($message->id))->handle(app(QueueCommunication::class));

    $minutes = [];
    Queue::assertPushed(SendCommunication::class, function (SendCommunication $job) use (&$minutes): bool {
        $minutes[] = $job->delay instanceof DateTimeInterface ? (int) round(now()->diffInMinutes($job->delay)) : -1;

        return true;
    });
    sort($minutes);

    expect($minutes)->toBe([0, 0, 1, 1, 2]);
});

it('al detenerlo, los correos que seguían en cola quedan sin enviar', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    consentingDonor();
    $message = bulkDraft(actor: $actor);
    app(SendBulkMessageTest::class)->handle($message, $actor);

    Queue::fake();
    app(SendBulkMessage::class)->handle($message->refresh(), $actor);
    (new PrepareBulkMessage($message->id))->handle(app(QueueCommunication::class));

    app(StopBulkMessage::class)->handle($message->refresh(), $actor);
    $communication = Communication::query()->where('bulk_message_id', $message->id)->sole();
    (new SendCommunication($communication->id))->handle(app(MessageComposer::class));

    expect($message->refresh()->status)->toBe(BulkMessageStatus::Stopped)
        ->and($message->stopped_by_id)->toBe($actor->id)
        ->and($communication->refresh()->status)->toBe(CommunicationStatus::Skipped)
        ->and($communication->skip_reason)->toContain('se detuvo')
        ->and(Mail::sent(DonorMessage::class)->count())->toBe(1); // solo la prueba
});

it('no reenvía ni edita un envío iniciado, y solo borra borradores', function (): void {
    $actor = userWithRole(Role::Administrator);
    consentingDonor();
    $sent = testedAndSent(bulkDraft(actor: $actor), $actor)->refresh();

    expect(fn () => app(SaveBulkMessage::class)->handle($sent, ['subject' => 'x', 'body' => 'y'], $actor))->toThrow(ValidationException::class, 'borrador')
        ->and(fn () => app(DeleteBulkMessage::class)->handle($sent, $actor))->toThrow(ValidationException::class)
        ->and(fn () => app(SendBulkMessage::class)->handle($sent, $actor))->toThrow(ValidationException::class, 'ya se inició');

    $draft = bulkDraft(actor: $actor);
    app(DeleteBulkMessage::class)->handle($draft, $actor);
    expect(BulkMessage::query()->whereKey($draft->id)->exists())->toBeFalse();
});

it('el Coordinador crea, prueba y envía desde el panel', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    actingAs($actor);
    $tag = Tag::query()->create(['name' => 'Voluntarios']);
    $donor = consentingDonor();
    $donor->tags()->attach($tag);
    consentingDonor();

    Livewire::test(CreateBulkMessage::class)
        ->fillForm(['subject' => 'Gracias, voluntarios', 'body' => 'Hola, {{ nombre }}.', 'tag_ids' => [$tag->id]])
        ->assertSee('1 recibirán el correo')
        ->call('create')
        ->assertHasNoFormErrors();

    $message = BulkMessage::query()->sole();

    Livewire::test(ViewBulkMessage::class, ['record' => $message->id])
        ->assertActionDisabled('send')
        ->callAction('sendTest')
        ->assertActionEnabled('send')
        ->callAction('send');

    expect($message->refresh()->status)->toBe(BulkMessageStatus::Sent)
        ->and(Communication::query()->where('bulk_message_id', $message->id)->pluck('donor_id')->all())->toBe([$donor->id]);

    Livewire::test(ViewBulkMessage::class, ['record' => $message->id])
        ->assertActionHidden('edit')
        ->assertActionHidden('send')
        ->assertActionVisible('stop');
});

it('el Contador consulta los envíos masivos pero no los prepara', function (): void {
    actingAs(userWithRole(Role::Accountant));
    $message = bulkDraft();

    Livewire::test(ListBulkMessages::class)->assertCanSeeTableRecords([$message])->assertActionHidden('create');
    Livewire::test(ViewBulkMessage::class, ['record' => $message->id])
        ->assertActionHidden('sendTest')
        ->assertActionHidden('send')
        ->assertActionHidden('edit');

    actingAs(userWithRole(Role::ReadOnly));
    get(BulkMessageResource::getUrl('index'))->assertForbidden();
});
