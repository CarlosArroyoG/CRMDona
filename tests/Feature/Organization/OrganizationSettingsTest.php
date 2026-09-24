<?php

declare(strict_types=1);

use App\Actions\Organization\UpdateOrganizationSettings;
use App\Enums\Role;
use App\Filament\Pages\OrganizationSettings;
use App\Models\OrganizationSetting;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('mantiene una sola fila de configuración', function (): void {
    expect(OrganizationSetting::current()->id)->toBe(1)
        ->and(OrganizationSetting::current()->id)->toBe(1)
        ->and(OrganizationSetting::query()->count())->toBe(1)
        ->and(fn () => DB::transaction(fn () => DB::table('organization_settings')->insert(['id' => 2])))
        ->toThrow(QueryException::class);
});

it('guarda los datos de la organización', function (): void {
    $settings = app(UpdateOrganizationSettings::class)->handle([
        'legal_name' => 'Fundación Ficticia A.C.',
        'rfc' => 'zzz900101ab1',
        'tax_regime' => '603',
        'tax_postal_code' => '62000',
        'authorization_number' => '600-04-01-2026-0001',
        'authorization_date' => '2026-01-15',
        'donation_legend' => 'Leyenda de ejemplo.',
        'privacy_notice_url' => 'https://example.com/aviso-de-privacidad',
        'privacy_notice_version' => '2026-09',
    ]);

    expect($settings->rfc)->toBe('ZZZ900101AB1')
        ->and($settings->hasPrivacyNotice())->toBeTrue();
});

it('exige URL y versión del aviso juntas', function (): void {
    $errors = [];
    try {
        app(UpdateOrganizationSettings::class)->handle(['privacy_notice_url' => 'https://example.com/aviso']);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toHaveKey('privacy_notice_version')
        ->and(OrganizationSetting::current()->hasPrivacyNotice())->toBeFalse();
});

it('el logotipo rechaza SVG y extensiones ejecutables aunque el contenido sea una imagen', function (string $name, string $content): void {
    Storage::fake('public');
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(OrganizationSettings::class)
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent($name, $content)])
        ->call('save')
        ->assertHasErrors(['data.data.logo_path']);

    expect(OrganizationSetting::current()->logo_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles('organization'))->toBe([]);
})->with([
    'SVG con script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'PNG real con extensión .svg' => ['logo.svg', tinyPng()],
    'PNG real con script y extensión .html' => ['logo.html', tinyPng().'<script>alert(1)</script>'],
]);

it('el logotipo acepta un PNG válido', function (): void {
    Storage::fake('public');
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(OrganizationSettings::class)
        ->fillForm(['logo_path' => UploadedFile::fake()->createWithContent('logo.png', tinyPng())])
        ->call('save')
        ->assertHasNoErrors();

    expect(OrganizationSetting::current()->logo_path)->toStartWith('organization/')->toEndWith('.png');
});

function tinyPng(): string
{
    return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}
