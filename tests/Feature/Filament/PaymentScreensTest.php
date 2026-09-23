<?php

declare(strict_types=1);

use App\Actions\Incidents\OpenPaymentIncident;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\PaymentProvider;
use App\Enums\RefundStatus;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\OrganizationSettings;
use App\Filament\Resources\PaymentIncidents\Pages\ListPaymentIncidents;
use App\Filament\Resources\PaymentIncidents\Pages\ViewPaymentIncident;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Resources\Users\Pages\EditUserPage;
use App\Models\Donation;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\WebhookEvent;
use App\Payments\Gateways\FakeScenario;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('Solo lectura ve lo operativo del pago, sin información técnica, acciones ni exportación', function (): void {
    $payment = startFakeDonation(['amount' => '640.00'], FakeScenario::Success);
    actingAs(userWithRole(Role::ReadOnly));

    get("/admin/payments/{$payment->id}")
        ->assertOk()
        ->assertSee('$640.00')
        ->assertSee('Exitoso')
        ->assertDontSee('Información técnica')
        ->assertDontSee((string) $payment->external_id)
        ->assertDontSee('4242')
        ->assertDontSee('Motivo del último rechazo');

    Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertActionHidden('refund');
    Livewire::test(ListPayments::class)->assertTableActionHidden('export')->assertCanSeeTableRecords([$payment]);
});

it('el Coordinador ve lo operativo pero no el detalle técnico ni puede reembolsar', function (): void {
    $payment = startFakeDonation([], FakeScenario::Success);
    actingAs(userWithRole(Role::FundraisingCoordinator));

    get("/admin/payments/{$payment->id}")
        ->assertOk()
        ->assertDontSee('Información técnica')
        ->assertDontSee((string) $payment->external_id)
        ->assertDontSee('Intentos de cobro');

    Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertActionHidden('refund');
});

it('Administrador y Contador ven el detalle técnico e intentos', function (Role $role): void {
    $payment = startFakeDonation([], FakeScenario::Success);
    actingAs(userWithRole($role));

    get("/admin/payments/{$payment->id}")
        ->assertOk()
        ->assertSee('Información técnica')
        ->assertSee((string) $payment->external_id);
})->with([Role::Administrator, Role::Accountant]);

it('el Contador solicita un reembolso desde la pantalla con motivo y confirmación', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    actingAs(userWithRole(Role::Accountant));

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->callAction('refund', ['amount' => '120', 'reason' => 'donor_request'])
        ->assertHasNoActionErrors();

    $refund = Refund::query()->sole();
    expect($refund->amount)->toBe('120.00')->and($refund->status)->toBe(RefundStatus::Succeeded);
});

it('la pantalla de reembolso muestra los errores de validación en el formulario', function (): void {
    $payment = startFakeDonation(['amount' => '500.00'], FakeScenario::Success);
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->callAction('refund', ['amount' => '900', 'reason' => 'donor_request'])
        ->assertHasActionErrors(['amount']);

    Livewire::test(ViewPayment::class, ['record' => $payment->id])
        ->callAction('refund', ['amount' => '10', 'reason' => 'other', 'reason_comment' => ''])
        ->assertHasActionErrors(['reason_comment']);

    expect(Refund::query()->count())->toBe(0);
});

it('el Coordinador pausa un donativo mensual con motivo; el Contador no ve la acción', function (): void {
    $subscription = startFakeMonthlyDonation();

    actingAs(userWithRole(Role::Accountant));
    Livewire::test(ViewSubscription::class, ['record' => $subscription->id])
        ->assertActionHidden('pause')->assertActionHidden('cancel');

    actingAs(userWithRole(Role::FundraisingCoordinator));
    Livewire::test(ViewSubscription::class, ['record' => $subscription->id])
        ->callAction('pause', ['reason' => 'Pausa pedida por el donante'])
        ->assertHasNoActionErrors();

    expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::Paused);
});

it('el Coordinador solo ve incidencias operativas; una técnica le está prohibida', function (): void {
    $operational = app(OpenPaymentIncident::class)->handle(IncidentType::DisputeOpened, 'dispute:1:opened', payment: Payment::factory()->create());
    $technical = app(OpenPaymentIncident::class)->handle(IncidentType::WebhookUnprocessable, 'webhook:1:unprocessable', provider: PaymentProvider::Fake);

    actingAs(userWithRole(Role::FundraisingCoordinator));
    Livewire::test(ListPaymentIncidents::class)
        ->assertCanSeeTableRecords([$operational])
        ->assertCanNotSeeTableRecords([$technical]);

    get("/admin/payment-incidents/{$technical->id}")->assertNotFound();
    get("/admin/payment-incidents/{$operational->id}")
        ->assertOk()
        ->assertSee('Existe un contracargo que requiere revisión')
        ->assertDontSee('Información técnica')
        ->assertDontSee('dispute:1:opened');
});

it('toma, anota y resuelve una incidencia desde la pantalla', function (): void {
    $incident = app(OpenPaymentIncident::class)->handle(IncidentType::DisputeOpened, 'dispute:2:opened', payment: Payment::factory()->create());
    $accountant = userWithRole(Role::Accountant);
    actingAs($accountant);

    Livewire::test(ViewPaymentIncident::class, ['record' => $incident->id])
        ->callAction('take')
        ->callAction('addNote', ['body' => 'Revisando con el banco'])
        ->callAction('resolve', ['resolution' => 'Se entregó evidencia al proveedor'])
        ->assertHasNoActionErrors();

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::Resolved)
        ->and($incident->reviewing_by_id)->toBe($accountant->id)
        ->and($incident->notes()->count())->toBe(1);
});

it('el Administrador activa las alertas de un Contador desde Usuarios', function (): void {
    $accountant = userWithRole(Role::Accountant);
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(EditUserPage::class, ['record' => $accountant->id])
        ->fillForm(['receives_payment_alerts' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($accountant->fresh()?->receives_payment_alerts)->toBeTrue();
});

it('el Administrador configura los límites de donativos en línea', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(OrganizationSettings::class)
        ->fillForm(['online_donation_min_amount' => '20', 'online_donation_max_amount' => '10'])
        ->call('save')
        ->assertHasErrors(['data.online_donation_max_amount']);

    Livewire::test(OrganizationSettings::class)
        ->fillForm(['online_donation_min_amount' => '20', 'online_donation_max_amount' => ''])
        ->call('save')
        ->assertHasNoErrors();

    expect(OrganizationSetting::current()->online_donation_min_amount)->toBe('20.00')
        ->and(OrganizationSetting::current()->online_donation_max_amount)->toBeNull();
});

it('la pantalla de pasarelas nunca muestra llaves ni secretos', function (): void {
    config([
        'payments.providers.fake.webhook_secret' => 'whsec_no_debe_verse',
        'payments.providers.stripe.secret_key' => 'sk_test_no_debe_verse',
    ]);
    actingAs(userWithRole(Role::Administrator));

    get('/admin/pasarelas')
        ->assertOk()
        ->assertSee('Simulado (pruebas)')
        ->assertSee('Deshabilitada')
        ->assertSee('/webhooks/payments/stripe')
        ->assertDontSee('whsec_no_debe_verse')
        ->assertDontSee('sk_test_no_debe_verse');
});

it('la bandeja de webhooks muestra solo la evidencia permitida', function (): void {
    $payment = startFakeDonation([], FakeScenario::Pending);
    deliverFakeWebhook('payment.updated', 'payment', (string) $payment->external_id, 'evt_visible', [
        'data' => ['object' => ['id' => $payment->external_id, 'client_secret' => 'pi_secret_oculto']],
    ]);
    actingAs(userWithRole(Role::Administrator));

    get('/admin/webhook-events/'.WebhookEvent::query()->sole()->id)
        ->assertOk()
        ->assertSee('evt_visible')
        ->assertDontSee('pi_secret_oculto');
});

it('el donativo en línea muestra su origen y el pago, sin actor humano', function (): void {
    $payment = startFakeDonation([], FakeScenario::Success);
    actingAs(userWithRole(Role::Administrator));

    get('/admin/donations/'.Donation::query()->sole()->id)
        ->assertOk()
        ->assertSee('Pago en línea')
        ->assertSee("Ver pago #{$payment->id}")
        ->assertSee('Automático: pago en línea exitoso');
});
