<?php

declare(strict_types=1);

use App\Actions\Users\ResetUserPassword;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Filament\Pages\Auth\ChangePassword;
use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Models\AuditLog;
use App\Models\Export;
use App\Models\User;
use App\Policies\ExportPolicy;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\PendingCommand;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\assertGuest;

function resetFor(User $target, ?User $actor = null): string
{
    return app(ResetUserPassword::class)->generateTemporary($target, $actor ?? userWithRole(Role::Administrator));
}

function resetUserPasswordCommand(): PendingCommand
{
    $command = artisan('app:reset-user-password');
    assert($command instanceof PendingCommand);

    return $command;
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('solo el Administrador puede restablecer la contraseña de otro usuario', function (Role $role, bool $allowed): void {
    $actor = userWithRole($role);
    $target = userWithRole(Role::ReadOnly);

    expect(Gate::forUser($actor)->allows('resetPassword', $target))->toBe($allowed);
    if (! $allowed) {
        expect(fn () => resetFor($target, $actor))->toThrow(ValidationException::class);
    }
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, false],
    'Contador' => [Role::Accountant, false],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('no permite restablecerse a sí mismo', function (): void {
    $admin = userWithRole(Role::Administrator);

    expect(Gate::forUser($admin)->allows('resetPassword', $admin))->toBeFalse()
        ->and(fn () => resetFor($admin, $admin))->toThrow(ValidationException::class);
});

it('genera una contraseña temporal que cumple la política y solo guarda su hash', function (): void {
    $target = userWithRole(Role::Accountant);

    $temporary = resetFor($target);
    $row = (array) DB::table('users')->where('id', $target->id)->first();

    expect(Validator::make(['p' => $temporary], ['p' => [Password::defaults()]])->passes())->toBeTrue()
        ->and(strlen($temporary))->toBe(ResetUserPassword::TEMPORARY_PASSWORD_LENGTH)
        ->and(json_encode($row, JSON_THROW_ON_ERROR))->not->toContain($temporary)
        ->and(Hash::check($temporary, (string) $row['password']))->toBeTrue()
        ->and($target->fresh()?->mustChangePassword())->toBeTrue();
});

it('no cambia rol ni estado del usuario', function (): void {
    $target = userWithRole(Role::FundraisingCoordinator);

    resetFor($target);

    expect($target->fresh()?->role)->toBe(Role::FundraisingCoordinator)
        ->and($target->fresh()?->isActive())->toBeTrue();
});

it('obliga a cambiar la contraseña y bloquea las demás pantallas', function (): void {
    $target = userWithRole(Role::Administrator);
    resetFor($target);
    $target->refresh();

    actingAs($target)->get('/admin')->assertRedirect('/admin/profile');
    actingAs($target)->get('/admin/donations')->assertRedirect('/admin/profile');
    actingAs($target)->get('/admin/users')->assertRedirect('/admin/profile');
    actingAs($target)->get('/admin/profile')->assertOk()->assertSee('Tu contraseña es temporal');
});

it('niega permisos también en Livewire y en descargas mientras la contraseña sea temporal', function (): void {
    $target = userWithRole(Role::Administrator);
    resetFor($target);
    $target->refresh();
    actingAs($target);

    $export = new Export(['file_disk' => 'local', 'exporter' => 'x', 'total_rows' => 0]);
    $export->forceFill(['user_id' => $target->id]);

    expect(Livewire::getPersistentMiddleware())->toContain(EnsurePasswordIsCurrent::class)
        ->and((new ExportPolicy)->view($target, $export))->toBeFalse();
    Livewire::test(ListDonations::class)->assertForbidden();
});

it('permite el acceso a la pantalla de cambio mientras la temporal está vigente', function (): void {
    $target = userWithRole(Role::ReadOnly);
    resetFor($target);

    Carbon::setTestNow(now()->addHours(config()->integer('auth.temporary_password_ttl_hours') - 1));

    actingAs($target->refresh())->get('/admin/profile')->assertOk();
});

it('no da acceso con una temporal vencida y cierra la sesión', function (): void {
    $target = userWithRole(Role::ReadOnly);
    resetFor($target);

    Carbon::setTestNow(now()->addHours(config()->integer('auth.temporary_password_ttl_hours') + 1));

    actingAs($target->refresh())->get('/admin/profile')->assertRedirect('/admin/login');
    assertGuest();
});

it('toma la vigencia de la configuración', function (): void {
    config(['auth.temporary_password_ttl_hours' => 2]);
    $target = userWithRole(Role::ReadOnly);
    resetFor($target);

    Carbon::setTestNow(now()->addHours(3));

    expect($target->fresh()?->temporaryPasswordExpired())->toBeTrue();
});

it('generar una nueva temporal invalida la anterior', function (): void {
    $target = userWithRole(Role::Accountant);
    $first = resetFor($target);
    Carbon::setTestNow(now()->addHour());
    $second = resetFor($target);

    $hash = (string) $target->fresh()?->getAuthPassword();
    expect(Hash::check($first, $hash))->toBeFalse()
        ->and(Hash::check($second, $hash))->toBeTrue()
        ->and($target->fresh()?->password_change_required_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('al fijar una contraseña propia se elimina la obligación y vuelve el acceso normal', function (): void {
    $target = userWithRole(Role::Accountant);
    $temporary = resetFor($target);
    actingAs($target->refresh());

    Livewire::test(ChangePassword::class)
        ->fillForm(['currentPassword' => $temporary, 'password' => $temporary, 'passwordConfirmation' => $temporary])
        ->call('save')
        ->assertHasFormErrors(['password']);

    Livewire::test(ChangePassword::class)
        ->fillForm(['currentPassword' => $temporary, 'password' => 'definitiva-propia-2026', 'passwordConfirmation' => 'definitiva-propia-2026'])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $target->refresh();
    expect($fresh->mustChangePassword())->toBeFalse()
        ->and(Hash::check('definitiva-propia-2026', (string) $fresh->getAuthPassword()))->toBeTrue();
    actingAs($fresh)->get('/admin/donations')->assertOk();
});

it('invalida las sesiones abiertas con la contraseña anterior', function (): void {
    $target = userWithRole(Role::Accountant);
    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);
    $previousSessionHash = $guard->hashPasswordForCookie((string) $target->getAuthPassword());

    resetFor($target);

    actingAs($target->refresh())
        ->withSession(['password_hash_web' => $previousSessionHash])
        ->get('/admin/profile')
        ->assertRedirect('/admin/login');
});

it('registra el restablecimiento en la bitácora sin ningún secreto', function (): void {
    $admin = userWithRole(Role::Administrator);
    $target = userWithRole(Role::ReadOnly);
    actingAs($admin);

    $temporary = resetFor($target, $admin);

    $log = AuditLog::query()->where('event', AuditEvent::PasswordReset->value)->sole();
    $serialized = json_encode([$log->changed_fields, $log->old_values, $log->new_values], JSON_THROW_ON_ERROR);

    expect($log->auditable_id)->toBe($target->id)
        ->and($log->user_id)->toBe($admin->id)
        ->and($log->changed_fields)->toContain('password')
        ->and($log->old_values ?? [])->not->toHaveKey('password')
        ->and($log->new_values ?? [])->not->toHaveKey('password')
        ->and($serialized)->not->toContain($temporary)
        ->and($serialized)->not->toContain('$2y$')
        ->and($serialized)->not->toContain('remember_token');
});

it('un usuario desactivado no recupera acceso por restablecer su contraseña', function (): void {
    $target = User::factory()->withRole(Role::Accountant)->create(['deactivated_at' => now()]);

    $temporary = resetFor($target);

    expect($target->fresh()?->isActive())->toBeFalse()
        ->and(Hash::check($temporary, (string) $target->fresh()?->getAuthPassword()))->toBeTrue();
    actingAs($target->refresh())->get('/admin/profile')->assertForbidden();
});

it('muestra la contraseña temporal una sola vez desde la pantalla de Usuarios', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $target = userWithRole(Role::ReadOnly);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('resetPassword')->table($target))
        ->assertNotified();

    expect($target->fresh()?->mustChangePassword())->toBeTrue();
});

it('recupera el acceso desde la consola sin cambiar rol ni imprimir la contraseña', function (): void {
    $admin = userWithRole(Role::Administrator);

    resetUserPasswordCommand()
        ->expectsQuestion('Correo electrónico del usuario', strtoupper($admin->email))
        ->expectsConfirmation('¿Restablecer la contraseña de este usuario?', 'yes')
        ->expectsQuestion('Nueva contraseña temporal', 'recuperacion-segura-2026')
        ->expectsQuestion('Confirma la contraseña temporal', 'recuperacion-segura-2026')
        ->expectsOutputToContain('Contraseña restablecida para '.$admin->email)
        ->doesntExpectOutputToContain('recuperacion-segura-2026')
        ->assertSuccessful();

    $fresh = $admin->fresh();
    expect($fresh?->role)->toBe(Role::Administrator)
        ->and($fresh?->mustChangePassword())->toBeTrue()
        ->and(Hash::check('recuperacion-segura-2026', (string) $fresh?->getAuthPassword()))->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::PasswordReset->value)->where('auditable_id', $admin->id)->exists())->toBeTrue();
});

it('no crea usuarios desde la consola', function (): void {
    $before = User::query()->count();

    resetUserPasswordCommand()
        ->expectsQuestion('Correo electrónico del usuario', 'nadie@example.com')
        ->expectsOutputToContain('No existe un usuario con ese correo')
        ->assertFailed();

    expect(User::query()->count())->toBe($before);
});

it('aplica la política de contraseña en la consola', function (): void {
    $user = userWithRole(Role::ReadOnly);
    $hash = (string) $user->getAuthPassword();

    resetUserPasswordCommand()
        ->expectsQuestion('Correo electrónico del usuario', $user->email)
        ->expectsConfirmation('¿Restablecer la contraseña de este usuario?', 'yes')
        ->expectsQuestion('Nueva contraseña temporal', 'corta1')
        ->expectsQuestion('Confirma la contraseña temporal', 'corta1')
        ->expectsOutputToContain('No se restableció la contraseña.')
        ->doesntExpectOutputToContain('corta1')
        ->assertFailed();

    expect($user->fresh()?->getAuthPassword())->toBe($hash)
        ->and($user->fresh()?->mustChangePassword())->toBeFalse();
});
