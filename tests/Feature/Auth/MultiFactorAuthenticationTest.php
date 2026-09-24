<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// MFA nativo de Filament, obligatorio para todo el panel (#45).

const MFA_SET_UP_ROUTE = 'filament.admin.auth.multi-factor-authentication.set-up-required';

function mfa(): AppAuthentication
{
    return AppAuthentication::make();
}

/**
 * Primer paso del login: correo y contraseña correctos.
 *
 * @return Testable<Login>
 */
function loginWithPassword(User $user): Testable
{
    return Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'password')
        ->call('authenticate');
}

it('exige configurar la aplicación autenticadora a los cuatro roles antes de usar el panel', function (Role $role): void {
    actingAs(User::factory()->withRole($role)->withoutMultiFactorAuthentication()->create());

    get('/admin')->assertRedirect(route(MFA_SET_UP_ROUTE));
    get('/admin/profile')->assertRedirect(route(MFA_SET_UP_ROUTE));
    get(route(MFA_SET_UP_ROUTE))->assertOk();
})->with(Role::cases());

it('con la aplicación autenticadora configurada, cada rol entra al panel', function (Role $role): void {
    $user = User::factory()->withRole($role)->withoutMultiFactorAuthentication()->create();
    actingAs($user);
    get('/admin')->assertRedirect(route(MFA_SET_UP_ROUTE));

    // Lo que guarda el asistente nativo al terminar el enrolamiento.
    mfa()->saveSecret($user, mfa()->generateSecret());

    get('/admin')->assertOk();
})->with(Role::cases());

it('sin sesión no se llega al panel ni a la configuración del MFA', function (): void {
    get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    get(route(MFA_SET_UP_ROUTE))->assertRedirect(route('filament.admin.auth.login'));
});

it('la contraseña correcta no basta: sin segundo factor no hay sesión', function (Role $role): void {
    $user = User::factory()->withRole($role)->create();

    loginWithPassword($user)->assertNoRedirect();
    expect(auth()->check())->toBeFalse();

    loginWithPassword($user)
        ->set('data.multiFactor.app.code', '000000')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.code'])
        ->assertNoRedirect();
    expect(auth()->check())->toBeFalse();
})->with(Role::cases());

it('un código TOTP válido completa el acceso', function (Role $role): void {
    $user = User::factory()->withRole($role)->create();

    loginWithPassword($user)
        ->set('data.multiFactor.app.code', mfa()->getCurrentCode($user))
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/admin');

    expect(auth()->id())->toBe($user->id);
})->with(Role::cases());

it('un código de recuperación permite entrar una sola vez', function (): void {
    $user = User::factory()->withRole(Role::Accountant)->create();
    mfa()->saveRecoveryCodes($user, ['codigo-uno-0001', 'codigo-dos-0002']);

    $useRecoveryCode = fn () => loginWithPassword($user)
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'codigo-uno-0001')
        ->call('authenticate');

    $useRecoveryCode()->assertHasNoErrors()->assertRedirect('/admin');
    expect(auth()->id())->toBe($user->id);

    auth()->logout();
    $useRecoveryCode()->assertHasErrors(['data.multiFactor.app.recoveryCode'])->assertNoRedirect();
    expect(auth()->check())->toBeFalse()
        ->and(mfa()->getRecoveryCodes($user->refresh()))->toHaveCount(1);
});

it('con contraseña temporal y sin MFA, configura el MFA y luego cambia la contraseña sin bucles', function (): void {
    actingAs(User::factory()->withRole(Role::ReadOnly)->withoutMultiFactorAuthentication()->create(['password_change_required_at' => now()]));

    get('/admin')->assertRedirect('/admin/profile');
    get('/admin/profile')->assertRedirect(route(MFA_SET_UP_ROUTE));
    get(route(MFA_SET_UP_ROUTE))->assertOk();
});

it('el secreto y los códigos de recuperación se guardan protegidos y no llegan a la bitácora ni a los logs', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });
    $user = User::factory()->withRole(Role::Administrator)->withoutMultiFactorAuthentication()->create();
    $secret = mfa()->generateSecret();
    actingAs($user);
    $auditsBefore = AuditLog::query()->count();

    mfa()->saveSecret($user, $secret);
    mfa()->saveRecoveryCodes($user, ['codigo-secreto-9999']);
    auth()->logout();
    loginWithPassword($user)->set('data.multiFactor.app.code', mfa()->getCurrentCode($user))->call('authenticate');

    $row = (array) DB::table('users')->where('id', $user->id)->first(['app_authentication_secret', 'app_authentication_recovery_codes']);
    $audits = AuditLog::query()->get()->toJson();

    $logs = implode("\n", $logged);
    $leaks = fn (string $haystack): bool => str_contains($haystack, $secret) || str_contains($haystack, 'codigo-secreto-9999');

    expect($leaks((string) $row['app_authentication_secret']))->toBeFalse()
        ->and($leaks((string) $row['app_authentication_recovery_codes']))->toBeFalse()
        ->and(array_key_exists('app_authentication_secret', $user->refresh()->toArray()))->toBeFalse()
        ->and(array_key_exists('app_authentication_recovery_codes', $user->toArray()))->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($auditsBefore)
        ->and($leaks($audits) || str_contains($audits, 'app_authentication'))->toBeFalse()
        ->and($leaks($logs))->toBeFalse();
});
