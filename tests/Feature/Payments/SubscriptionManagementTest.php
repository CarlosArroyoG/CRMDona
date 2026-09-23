<?php

declare(strict_types=1);

use App\Actions\Subscriptions\CancelSubscription;
use App\Actions\Subscriptions\PauseSubscription;
use App\Actions\Subscriptions\ResumeSubscription;
use App\Enums\AuditEvent;
use App\Enums\CancellationSource;
use App\Enums\IncidentType;
use App\Enums\Role;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\PaymentIncident;
use App\Models\Subscription;
use App\Payments\Exceptions\ProviderUnavailableException;
use App\Payments\Gateways\FakeScenario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

it('pausa y reanuda con motivo, quién y cuándo, y lo audita', function (): void {
    $subscription = startFakeMonthlyDonation();
    $coordinator = userWithRole(Role::FundraisingCoordinator);
    actingAs($coordinator);

    $paused = app(PauseSubscription::class)->handle($subscription, 'El donante pidió pausar tres meses', $coordinator);
    expect($paused->status)->toBe(SubscriptionStatus::Paused)
        ->and($paused->paused_by_id)->toBe($coordinator->id)
        ->and($paused->paused_at)->not->toBeNull();

    $resumed = app(ResumeSubscription::class)->handle($paused, 'El donante pidió reanudar', $coordinator);
    expect($resumed->status)->toBe(SubscriptionStatus::Active)
        ->and($resumed->resumed_by_id)->toBe($coordinator->id);

    $events = AuditLog::query()->where('auditable_type', 'subscription')->where('auditable_id', $subscription->id)->get();
    $pause = $events->firstWhere('event', AuditEvent::Paused);
    expect($pause?->user_id)->toBe($coordinator->id)
        ->and($pause?->new_values)->toMatchArray(['status' => 'paused', 'reason' => 'El donante pidió pausar tres meses'])
        ->and($events->firstWhere('event', AuditEvent::Resumed))->not->toBeNull();
});

it('cancelar desde el CRM registra actor, motivo y origen, y no abre incidencia aunque llegue la notificación', function (): void {
    $subscription = startFakeMonthlyDonation();
    $admin = userWithRole(Role::Administrator);
    actingAs($admin);

    $cancelled = app(CancelSubscription::class)->handle($subscription, 'El donante ya no desea donar', $admin);
    deliverFakeWebhook('customer.subscription.deleted', 'subscription', (string) $subscription->external_id);

    $cancelled->refresh();
    expect($cancelled->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($cancelled->cancellation_source)->toBe(CancellationSource::CrmUser)
        ->and($cancelled->cancelled_by_id)->toBe($admin->id)
        ->and($cancelled->cancellation_reason)->toBe('El donante ya no desea donar')
        ->and(PaymentIncident::query()->where('type', IncidentType::SubscriptionCancelledByProvider->value)->count())->toBe(0);
});

it('si el proveedor falla al cancelar, la suscripción sigue activa y se retira la intención', function (): void {
    $subscription = startFakeMonthlyDonation();
    $admin = userWithRole(Role::Administrator);
    fakeGateway()->willReturn(FakeScenario::ProviderUnavailable);

    expect(fn () => app(CancelSubscription::class)->handle($subscription, 'Motivo de prueba', $admin))
        ->toThrow(ProviderUnavailableException::class);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->cancellation_source)->toBeNull()
        ->and($subscription->cancelled_by_id)->toBeNull();
});

it('solo Administrador y Coordinador pausan, reanudan y cancelan', function (Role $role, bool $allowed): void {
    $subscription = startFakeMonthlyDonation();
    $actor = userWithRole($role);

    $attempt = fn () => app(PauseSubscription::class)->handle($subscription, 'Motivo suficiente', $actor);

    if ($allowed) {
        expect($attempt()->status)->toBe(SubscriptionStatus::Paused);
    } else {
        expect($attempt)->toThrow(AuthorizationException::class);
        expect($subscription->fresh()?->status)->toBe(SubscriptionStatus::Active);
    }
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, true],
    'Contador' => [Role::Accountant, false],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('exige motivo y un estado válido', function (): void {
    $subscription = startFakeMonthlyDonation();
    $admin = userWithRole(Role::Administrator);

    expect(fn () => app(PauseSubscription::class)->handle($subscription, '', $admin))->toThrow(ValidationException::class)
        ->and(fn () => app(ResumeSubscription::class)->handle($subscription, 'Reanudar algo activo', $admin))->toThrow(ValidationException::class);

    app(CancelSubscription::class)->handle($subscription, 'Cancelación de prueba', $admin);
    expect(fn () => app(CancelSubscription::class)->handle($subscription->fresh() ?? $subscription, 'Otra vez', $admin))
        ->toThrow(ValidationException::class);
});

it('una suscripción pendiente sin identificador externo se cancela solo en el CRM', function (): void {
    $subscription = Subscription::factory()->create(['external_id' => null, 'status' => SubscriptionStatus::Pending]);
    $admin = userWithRole(Role::Administrator);

    expect(app(CancelSubscription::class)->handle($subscription, 'Nunca se completó el alta', $admin)->status)
        ->toBe(SubscriptionStatus::Cancelled);
});
