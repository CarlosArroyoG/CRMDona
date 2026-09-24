<?php

declare(strict_types=1);

use App\Actions\Donors\PrepareBirthdayWhatsApp;
use App\Enums\AuditEvent;
use App\Enums\CommunicationKind;
use App\Enums\Role;
use App\Filament\Resources\Donors\Pages\ViewDonor;
use App\Filament\Widgets\UpcomingBirthdays;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Donor;
use App\Models\MessageTemplate;
use App\Models\OrganizationSetting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    OrganizationSetting::current()->forceFill(['legal_name' => 'FUNDACION DE PRUEBA'])->save();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function birthdayDonor(array $attributes = []): Donor
{
    return Donor::factory()->create([
        'first_name' => 'María', 'phone' => '777 123 4567', 'accepts_communications' => true,
        'birth_date' => now()->subYears(40)->toDateString(), ...$attributes,
    ]);
}

it('arma wa.me con la plantilla de cumpleaños (la misma del correo) y registra "WhatsApp preparado" sin teléfono ni texto', function (): void {
    MessageTemplate::query()->create(['kind' => CommunicationKind::Birthday->value, 'subject' => 'Feliz día', 'body' => 'Querida {{ nombre }}, {{ organizacion }} te abraza.']);
    $donor = birthdayDonor();

    $url = app(PrepareBirthdayWhatsApp::class)->handle($donor, userWithRole(Role::FundraisingCoordinator));

    expect($url)->toBe('https://wa.me/527771234567?text='.rawurlencode('Querida María, FUNDACION DE PRUEBA te abraza.'));

    $audit = AuditLog::query()->where('auditable_type', 'donor')->where('event', AuditEvent::WhatsAppPrepared->value)->sole();
    $stored = (string) json_encode($audit->toArray());
    expect($stored)->not->toContain('7771234567');
    expect($stored)->not->toContain('te abraza');
    expect(AuditEvent::WhatsAppPrepared->getLabel())->toContain('preparado');
    expect(AuditEvent::WhatsAppPrepared->getLabel())->not->toContain('enviado');
    // No es un envío del CRM: no entra al historial de comunicaciones.
    expect(Communication::query()->count())->toBe(0);
});

it('consentimiento y teléfono: sin consentimiento, archivado o teléfono ambiguo no se prepara', function (array $attributes, string $reason): void {
    $donor = birthdayDonor($attributes);

    expect(PrepareBirthdayWhatsApp::unavailableReason($donor))->toBe($reason)
        ->and(fn () => app(PrepareBirthdayWhatsApp::class)->handle($donor, userWithRole(Role::Administrator)))->toThrow(ValidationException::class);
    expect(AuditLog::query()->where('event', AuditEvent::WhatsAppPrepared->value)->count())->toBe(0);
})->with([
    'no acepta comunicaciones' => [['accepts_communications' => false], 'El donante no acepta recibir comunicaciones.'],
    'archivado' => [['archived_at' => now()], 'El donante está archivado.'],
    'sin teléfono' => [['phone' => null], 'El donante no tiene un teléfono utilizable para WhatsApp.'],
    'teléfono ambiguo' => [['phone' => '17771234567'], 'El donante no tiene un teléfono utilizable para WhatsApp.'],
]);

it('permisos: Administrador, Coordinador y Contador preparan; Solo lectura no', function (Role $role, bool $allowed): void {
    $attempt = fn () => app(PrepareBirthdayWhatsApp::class)->handle(birthdayDonor(), userWithRole($role));

    $allowed ? expect($attempt())->toStartWith('https://wa.me/') : expect($attempt)->toThrow(AuthorizationException::class);
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, true],
    'Contador' => [Role::Accountant, true],
    'Solo lectura' => [Role::ReadOnly, false],
]);

it('ficha del donante: la acción avisa que solo se abrió WhatsApp; Solo lectura no la ve', function (): void {
    $donor = birthdayDonor();

    actingAs(userWithRole(Role::FundraisingCoordinator));
    Livewire::test(ViewDonor::class, ['record' => $donor->id])
        ->assertActionVisible('prepareWhatsApp')
        ->callAction('prepareWhatsApp')
        ->assertNotified('Se abrió WhatsApp; confirma el envío ahí.');

    actingAs(userWithRole(Role::ReadOnly));
    Livewire::test(ViewDonor::class, ['record' => $donor->id])->assertActionHidden('prepareWhatsApp');

    actingAs(userWithRole(Role::Administrator));
    Livewire::test(ViewDonor::class, ['record' => birthdayDonor(['accepts_communications' => false])->id])->assertActionHidden('prepareWhatsApp');
});

it('cumpleaños del Escritorio: ofrece "Preparar WhatsApp" solo a quien puede y lo registra al usarlo', function (): void {
    $donor = birthdayDonor(['birth_date' => now()->subYears(30)->toDateString()]);
    birthdayDonor(['first_name' => 'Pedro', 'accepts_communications' => false, 'birth_date' => now()->subYears(20)->toDateString()]);

    actingAs(userWithRole(Role::FundraisingCoordinator));
    Livewire::test(UpcomingBirthdays::class)
        ->assertSee('Preparar WhatsApp')
        ->callAction('prepareWhatsApp', arguments: ['donor' => $donor->id])
        ->assertNotified('Se abrió WhatsApp; confirma el envío ahí.');

    expect(AuditLog::query()->where('event', AuditEvent::WhatsAppPrepared->value)->count())->toBe(1);

    actingAs(userWithRole(Role::ReadOnly));
    Livewire::test(UpcomingBirthdays::class)->assertDontSee('Preparar WhatsApp');
});
