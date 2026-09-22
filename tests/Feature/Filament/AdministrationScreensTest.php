<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Pages\Auth\ChangePassword;
use App\Filament\Pages\OrganizationSettings;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Programs\Pages\CreateProgram;
use App\Filament\Resources\Users\Pages\CreateUserPage;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Program;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('crea usuarios con rol desde la pantalla de Usuarios', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(CreateUserPage::class)
        ->fillForm([
            'name' => 'Contadora Ficticia',
            'email' => 'contadora@example.com',
            'role' => Role::Accountant->value,
            'password' => 'clave-segura-2026',
            'password_confirmation' => 'clave-segura-2026',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::query()->where('email', 'contadora@example.com')->value('role'))->toBe(Role::Accountant);
});

it('muestra el error de contraseña débil en el campo', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(CreateUserPage::class)
        ->fillForm(['name' => 'X', 'email' => 'x@example.com', 'role' => 'read_only', 'password' => 'debil', 'password_confirmation' => 'debil'])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

it('no permite desactivarse a sí mismo desde la lista', function (): void {
    $admin = userWithRole(Role::Administrator);
    actingAs($admin);
    $other = userWithRole(Role::ReadOnly);

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('toggleActive')->table($admin))
        ->callAction(TestAction::make('toggleActive')->table($other));

    expect($other->fresh()?->isActive())->toBeFalse();
});

it('permite a cualquier rol cambiar su propia contraseña', function (): void {
    $user = userWithRole(Role::ReadOnly);
    $user->forceFill(['password' => 'clave-anterior-2026'])->save();
    actingAs($user);

    Livewire::test(ChangePassword::class)
        ->fillForm([
            'currentPassword' => 'clave-anterior-2026',
            'password' => 'clave-nueva-2026',
            'passwordConfirmation' => 'clave-nueva-2026',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('clave-nueva-2026', (string) $user->fresh()?->getAuthPassword()))->toBeTrue();
});

it('crea programas y campañas desde sus formularios', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));

    Livewire::test(CreateProgram::class)->fillForm(['name' => 'Formación', 'status' => 'active'])->call('create')->assertHasNoFormErrors();
    $program = Program::query()->where('slug', 'formacion')->sole();

    Livewire::test(CreateCampaign::class)
        ->fillForm(['name' => 'Regreso a clases 2027', 'status' => 'draft', 'program_id' => $program->id, 'goal_amount' => '80000'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Campaign::query()->where('program_id', $program->id)->value('goal_amount'))->toBe('80000.00');
});

it('deja al Contador consultar la organización sin poder guardar', function (): void {
    actingAs(userWithRole(Role::Accountant));

    Livewire::test(OrganizationSettings::class)
        ->assertSuccessful()
        ->assertDontSee('Guardar')
        ->call('save')
        ->assertForbidden();
});

it('guarda la configuración de la organización desde su pantalla', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(OrganizationSettings::class)
        ->fillForm(['legal_name' => 'Fundación Ficticia A.C.', 'privacy_notice_url' => 'https://example.com/aviso', 'privacy_notice_version' => '2026-09'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(OrganizationSetting::current()->hasPrivacyNotice())->toBeTrue();
});

it('muestra el detalle de la bitácora sin datos personales', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $donor = Donor::factory()->create(['first_name' => 'Nombreprivado']);
    $donor->update(['first_name' => 'Otronombre']);
    $log = AuditLog::query()->where('auditable_type', 'donor')->latest('id')->firstOrFail();

    Livewire::test(ViewAuditLog::class, ['record' => $log->id])
        ->assertSee('Nombre(s) (cambió; valor no registrado por privacidad)')
        ->assertDontSee('Nombreprivado')
        ->assertDontSee('Otronombre');
});
