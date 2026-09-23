<?php

declare(strict_types=1);

use App\Actions\Incidents\AddIncidentNote;
use App\Actions\Incidents\OpenPaymentIncident;
use App\Actions\Incidents\ResolveIncident;
use App\Actions\Incidents\TakeIncidentForReview;
use App\Actions\Users\SetPaymentAlertPreference;
use App\Enums\AuditEvent;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Enums\Role;
use App\Jobs\SendPaymentIncidentAlert;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PaymentIncident;
use App\Models\PaymentIncidentNote;
use App\Models\User;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function openIncident(IncidentType $type, string $key, ?Payment $payment = null): PaymentIncident
{
    return app(OpenPaymentIncident::class)->handle($type, $key, payment: $payment ?? Payment::factory()->create(), provider: PaymentProvider::Fake);
}

it('el mismo hecho no duplica la incidencia ni la alerta, aunque ya esté resuelta', function (): void {
    $admin = userWithRole(Role::Administrator);
    $payment = Payment::factory()->create();

    $first = openIncident(IncidentType::OneTimePaymentFailed, "payment:{$payment->id}:final_failed", $payment);
    app(ResolveIncident::class)->handle($first, 'Se contactó al donante', $admin);
    $again = openIncident(IncidentType::OneTimePaymentFailed, "payment:{$payment->id}:final_failed", $payment);

    expect($again->id)->toBe($first->id)
        ->and($again->status)->toBe(IncidentStatus::Resolved)
        ->and(PaymentIncident::query()->count())->toBe(1)
        ->and(DatabaseNotification::query()->where('notifiable_id', $admin->id)->count())->toBe(1);
});

it('un hecho distinto sobre el mismo pago abre otra incidencia legítima', function (): void {
    $payment = Payment::factory()->create();
    $first = openIncident(IncidentType::RecurringAttemptFailed, 'attempt:1:failed', $payment);
    app(ResolveIncident::class)->handle($first, 'Se resolvió el primero', userWithRole(Role::Administrator));

    openIncident(IncidentType::RecurringAttemptFailed, 'attempt:2:failed', $payment);

    expect(PaymentIncident::query()->where('payment_id', $payment->id)->count())->toBe(2);
});

it('sigue el flujo Nueva → En revisión → Resuelta con quién y cuándo, y lo audita', function (): void {
    $accountant = userWithRole(Role::Accountant);
    $incident = openIncident(IncidentType::RefundFailed, 'refund:1:failed');

    $reviewing = app(TakeIncidentForReview::class)->handle($incident, $accountant);
    expect($reviewing->status)->toBe(IncidentStatus::Reviewing)
        ->and($reviewing->reviewing_by_id)->toBe($accountant->id)
        ->and($reviewing->reviewing_started_at)->not->toBeNull();

    $resolved = app(ResolveIncident::class)->handle($reviewing, 'Se reintentó en el panel del proveedor', $accountant);
    expect($resolved->status)->toBe(IncidentStatus::Resolved)
        ->and($resolved->resolved_by_id)->toBe($accountant->id)
        ->and($resolved->resolution)->toBe('Se reintentó en el panel del proveedor')
        ->and(fn () => app(ResolveIncident::class)->handle($resolved, 'Otra vez', $accountant))->toThrow(ValidationException::class)
        ->and(fn () => app(TakeIncidentForReview::class)->handle($resolved, $accountant))->toThrow(ValidationException::class);

    $events = AuditLog::query()->where('auditable_type', 'payment_incident')->pluck('event')->all();
    expect($events)->toContain(AuditEvent::Created, AuditEvent::IncidentTaken, AuditEvent::IncidentResolved);
});

it('leer la notificación no cambia el estado de la incidencia', function (): void {
    $admin = userWithRole(Role::Administrator);
    $incident = openIncident(IncidentType::DisputeOpened, 'dispute:1:opened');

    $admin->unreadNotifications()->get()->each(fn (DatabaseNotification $n) => $n->markAsRead());

    expect($incident->fresh()?->status)->toBe(IncidentStatus::New);
});

it('las notas son de solo inserción y quedan auditadas sin copiar su texto', function (): void {
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    $incident = openIncident(IncidentType::OneTimePaymentFailed, 'payment:9:final_failed');

    $note = app(AddIncidentNote::class)->handle($incident, 'Llamé al donante; volverá a intentar', $coordinator);

    expect($note->user_id)->toBe($coordinator->id)
        ->and(fn () => DB::transaction(fn () => PaymentIncidentNote::query()->whereKey($note->id)->update(['body' => 'x'])))
        ->toThrow(QueryException::class, 'no se modifican')
        ->and(fn () => DB::transaction(fn () => PaymentIncidentNote::query()->whereKey($note->id)->delete()))
        ->toThrow(QueryException::class, 'no se modifican');

    $log = AuditLog::query()->where('auditable_type', 'payment_incident_note')->sole();
    expect($log->changed_fields)->toContain('body')
        ->and(json_encode($log->new_values, JSON_THROW_ON_ERROR))->not->toContain('Llamé');
});

it('el Coordinador atiende solo incidencias operativas; las técnicas son de Administrador y Contador', function (): void {
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    $operational = openIncident(IncidentType::DisputeOpened, 'dispute:5:opened');
    $technical = openIncident(IncidentType::WebhookUnprocessable, 'webhook:5:unprocessable');

    expect(app(TakeIncidentForReview::class)->handle($operational, $coordinator)->status)->toBe(IncidentStatus::Reviewing)
        ->and(fn () => app(TakeIncidentForReview::class)->handle($technical, $coordinator))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AddIncidentNote::class)->handle($technical, 'Nota', $coordinator))->toThrow(AuthorizationException::class)
        ->and(app(TakeIncidentForReview::class)->handle($technical, userWithRole(Role::Accountant))->status)->toBe(IncidentStatus::Reviewing);
});

it('Solo lectura no atiende incidencias', function (): void {
    $incident = openIncident(IncidentType::DisputeOpened, 'dispute:6:opened');

    expect(fn () => app(TakeIncidentForReview::class)->handle($incident, userWithRole(Role::ReadOnly)))
        ->toThrow(AuthorizationException::class);
});

it('alerta a todo Administrador y a Coordinadores y Contadores con la preferencia; nunca a Solo lectura', function (): void {
    $admin = userWithRole(Role::Administrator);
    $inactiveAdmin = User::factory()->withRole(Role::Administrator)->create(['deactivated_at' => now()]);
    $coordinatorOn = userWithRole(Role::FundraisingCoordinator);
    $coordinatorOn->forceFill(['receives_payment_alerts' => true])->save();
    $coordinatorOff = userWithRole(Role::FundraisingCoordinator);
    $accountantOn = userWithRole(Role::Accountant);
    $accountantOn->forceFill(['receives_payment_alerts' => true])->save();
    $readOnly = userWithRole(Role::ReadOnly);
    $readOnly->forceFill(['receives_payment_alerts' => true])->save();

    openIncident(IncidentType::DisputeOpened, 'dispute:7:opened');
    openIncident(IncidentType::WebhookUnprocessable, 'webhook:7:unprocessable');

    $count = fn (User $user): int => DatabaseNotification::query()->where('notifiable_id', $user->id)->count();
    expect($count($admin))->toBe(2)
        ->and($count($inactiveAdmin))->toBe(0)
        ->and($count($coordinatorOn))->toBe(1)
        ->and($count($coordinatorOff))->toBe(0)
        ->and($count($accountantOn))->toBe(2)
        ->and($count($readOnly))->toBe(0);
});

it('la alerta es operativa: sin códigos del proveedor ni identificadores externos', function (): void {
    $admin = userWithRole(Role::Administrator);
    $payment = startFakeDonation(['amount' => '321.00'], FakeScenario::Failed);

    $data = DatabaseNotification::query()->where('notifiable_id', $admin->id)->sole()->data;
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($json)->toContain('Pago único fallido')
        ->and($json)->toContain('$321.00')
        ->and($json)->not->toContain('processing_error')
        ->and($json)->not->toContain((string) $payment->external_id)
        ->and($json)->not->toContain('4242');
});

it('reenviar la alerta (Job reintentado) no avisa dos veces a la misma persona', function (): void {
    $admin = userWithRole(Role::Administrator);
    $incident = openIncident(IncidentType::DisputeOpened, 'dispute:8:opened');

    dispatch_sync(new SendPaymentIncidentAlert($incident->id));
    dispatch_sync(new SendPaymentIncidentAlert($incident->id));

    expect(DatabaseNotification::query()->where('notifiable_id', $admin->id)->count())->toBe(1);
});

it('la preferencia de alertas solo la cambia el Administrador y queda auditada', function (): void {
    $accountant = userWithRole(Role::Accountant);

    expect(fn () => app(SetPaymentAlertPreference::class)->handle($accountant, true, userWithRole(Role::FundraisingCoordinator)))
        ->toThrow(AuthorizationException::class);

    app(SetPaymentAlertPreference::class)->handle($accountant, true, userWithRole(Role::Administrator));
    expect($accountant->fresh()?->receives_payment_alerts)->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', 'user')->where('auditable_id', $accountant->id)->latest('id')->first()?->new_values)
        ->toBe(['receives_payment_alerts' => true]);
});

it('la base de datos exige evidencia de revisión y resolución', function (): void {
    $incident = openIncident(IncidentType::DisputeOpened, 'dispute:10:opened');

    expect(fn () => DB::transaction(fn () => DB::table('payment_incidents')->where('id', $incident->id)->update(['status' => 'resolved'])))
        ->toThrow(QueryException::class, 'payment_incidents_resolution_evidence')
        ->and(fn () => DB::transaction(fn () => DB::table('payment_incidents')->where('id', $incident->id)->update(['status' => 'reviewing'])))
        ->toThrow(QueryException::class, 'payment_incidents_reviewing_evidence');
});
