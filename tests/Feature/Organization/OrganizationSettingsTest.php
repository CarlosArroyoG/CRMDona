<?php

declare(strict_types=1);

use App\Actions\Organization\UpdateOrganizationSettings;
use App\Models\OrganizationSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
