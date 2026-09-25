<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Pages\OrganizationSettings;
use App\Models\OrganizationSetting;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA',
        'privacy_notice_url' => null, 'privacy_notice_version' => null,
    ])->save();
});

it('sin aviso configurado la página de donación no acepta donativos', function (): void {
    get('/donar')->assertStatus(503)->assertSee('no están disponibles');
});

it('el aviso del CRM muestra al responsable, lo que hace el sistema con los datos y no inventa lo que falta', function (): void {
    $html = (string) get('/aviso-de-privacidad')->assertOk()->getContent();

    expect($html)->toContain('FUNDACION DE PRUEBA')->toContain('RFC FPR010101AAA')
        ->toContain('código de seguridad (CVV)')->toContain('Contabilidad')->toContain('WhatsApp')
        ->toContain('20 días hábiles')->toContain('sin publicar');
    expect($html)->not->toContain('con domicilio en');
});

it('"Publicar aviso del CRM" exige domicilio y correo; con ellos fija URL y versión y habilita /donar', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(OrganizationSettings::class)
        ->callAction('publishCrmNotice')
        ->assertNotified('Faltan datos del aviso');
    expect(OrganizationSetting::current()->hasPrivacyNotice())->toBeFalse();

    Livewire::test(OrganizationSettings::class)
        ->fillForm(['privacy_address' => 'Calle Ejemplo 10, Col. Centro, 62000, Cuernavaca, Morelos', 'privacy_contact_email' => 'privacidad@example.org'])
        ->callAction('publishCrmNotice')
        ->assertNotified('Aviso de privacidad publicado');

    $settings = OrganizationSetting::current();
    expect($settings->privacy_notice_url)->toBe(route('privacy.notice'))
        ->and($settings->privacy_notice_version)->toBe(now(config()->string('app.timezone'))->format('Y-m-d'))
        ->and($settings->hasPrivacyNotice())->toBeTrue();

    get('/aviso-de-privacidad')->assertSee('con domicilio en Calle Ejemplo 10')->assertSee('privacidad@example.org')->assertSee('Versión '.$settings->privacy_notice_version);
    get('/donar')->assertOk()->assertSee(route('privacy.notice'), false);
});

it('solo el Administrador publica el aviso; el Contador lo ve sin la acción', function (): void {
    actingAs(userWithRole(Role::Accountant));

    Livewire::test(OrganizationSettings::class)->assertActionHidden('publishCrmNotice');
});
