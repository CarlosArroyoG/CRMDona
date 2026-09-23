<?php

declare(strict_types=1);

use App\Actions\Organization\UpdateOrganizationSettings;
use App\Actions\Payments\ValidateOnlineDonationAmount;
use App\Enums\PaymentProvider;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Payments\GatewayRegistry;
use Illuminate\Validation\ValidationException;

/**
 * @return array{min: string|null, max: string|null}
 */
function limitsForFake(): array
{
    return app(ValidateOnlineDonationAmount::class)->effectiveLimits(app(GatewayRegistry::class)->get(PaymentProvider::Fake));
}

it('sin límites de negocio ni técnicos no hay tope adicional', function (): void {
    expect(limitsForFake())->toBe(['min' => null, 'max' => null]);

    expect(startFakeDonation(['amount' => '1'])->amount)->toBe('1.00');
});

it('aplica el límite más restrictivo entre la regla del CRM y el límite técnico del proveedor', function (?string $businessMin, ?string $businessMax, ?string $technicalMin, ?string $technicalMax, array $expected): void {
    OrganizationSetting::current()->forceFill(['online_donation_min_amount' => $businessMin, 'online_donation_max_amount' => $businessMax])->save();
    config(['payments.providers.fake.min_amount' => $technicalMin, 'payments.providers.fake.max_amount' => $technicalMax]);

    expect(limitsForFake())->toBe($expected);
})->with([
    'solo técnico' => [null, null, '10.00', null, ['min' => '10.00', 'max' => null]],
    'negocio más alto que técnico' => ['50.00', null, '10.00', null, ['min' => '50.00', 'max' => null]],
    'negocio más bajo que técnico' => ['5.00', null, '10.00', null, ['min' => '10.00', 'max' => null]],
    'máximo de negocio y técnico' => [null, '20000.00', null, '9999.00', ['min' => null, 'max' => '9999.00']],
    'solo máximo de negocio' => [null, '20000.00', null, null, ['min' => null, 'max' => '20000.00']],
]);

it('rechaza importes fuera de los límites efectivos sin crear el pago', function (): void {
    OrganizationSetting::current()->forceFill(['online_donation_min_amount' => '50.00', 'online_donation_max_amount' => '1000.00'])->save();

    expect(fn () => startFakeDonation(['amount' => '49.99']))->toThrow(ValidationException::class, 'mínimo')
        ->and(fn () => startFakeDonation(['amount' => '1000.01']))->toThrow(ValidationException::class, 'máximo')
        ->and(Payment::query()->count())->toBe(0)
        ->and(startFakeDonation(['amount' => '50'])->amount)->toBe('50.00');
});

it('los límites de negocio se configuran como importes válidos y el máximo no puede ser menor que el mínimo', function (): void {
    $settings = app(UpdateOrganizationSettings::class)->handle(['online_donation_min_amount' => '25', 'online_donation_max_amount' => '5,000.50']);
    expect($settings->online_donation_min_amount)->toBe('25.00')
        ->and($settings->online_donation_max_amount)->toBe('5000.50');

    expect(fn () => app(UpdateOrganizationSettings::class)->handle(['online_donation_min_amount' => '100', 'online_donation_max_amount' => '50']))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateOrganizationSettings::class)->handle(['online_donation_min_amount' => '-5']))
        ->toThrow(ValidationException::class);

    $cleared = app(UpdateOrganizationSettings::class)->handle([]);
    expect($cleared->online_donation_min_amount)->toBeNull()->and($cleared->online_donation_max_amount)->toBeNull();
});
