<?php

declare(strict_types=1);

use App\Actions\Cfdi\BuildDonationCfdiDraft;
use App\Actions\Cfdi\BuildGlobalCfdiDraft;
use App\Actions\Cfdi\CloseGlobalCfdiPeriod;
use App\Actions\Cfdi\RequestDonationCfdi;
use App\Cfdi\Exceptions\CfdiNotReadyException;
use App\Enums\ManualPaymentMethod;
use App\Enums\TaxRegime;
use App\Jobs\ReconcileCfdis;
use App\Models\Cfdi;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\FiscalIncident;
use App\Models\GlobalCfdi;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'tax_postal_code' => '62000', 'authorization_number' => '600-04-02-2026-0001', 'authorization_date' => '2026-01-15', 'donation_legend' => BuildDonationCfdiDraft::DONATARIA_LEGEND,
    ])->save();
});

it('cierra el periodo diario anterior con trazabilidad e idempotencia', function (): void {
    $first = Donation::factory()->confirmed()->create(['received_on' => '2026-09-22', 'amount' => '200.00', 'manual_payment_method' => ManualPaymentMethod::Cash]);
    $second = Donation::factory()->confirmed()->create(['received_on' => '2026-09-22', 'amount' => '500.00', 'manual_payment_method' => ManualPaymentMethod::BankTransfer]);
    Donation::factory()->confirmed()->create(['received_on' => '2026-09-23']);

    $close = app(CloseGlobalCfdiPeriod::class);
    $global = $close->handle(CarbonImmutable::parse('2026-09-23 00:15:00', 'America/Mexico_City'));
    $again = $close->handle(CarbonImmutable::parse('2026-09-23 12:00:00', 'America/Mexico_City'));

    expect($global?->periodicity)->toBe('daily')
        ->and(substr((string) $global?->period_start, 0, 10))->toBe('2026-09-22')
        ->and(substr((string) $global?->period_end, 0, 10))->toBe('2026-09-22')
        ->and($global?->donations->pluck('id')->sort()->values()->all())->toBe([$first->id, $second->id])
        ->and($global?->donations->first()?->getRelationValue('pivot')->operation_number)->toContain('donation:')
        ->and($again?->id)->toBe($global?->id)
        ->and(GlobalCfdi::query()->count())->toBe(1)
        ->and(Cfdi::query()->where('global_cfdi_id', $global?->id)->count())->toBe(1);
});

it('respeta weekly y monthly sin mezclar periodos', function (string $periodicity, string $asOf, string $start, string $end): void {
    OrganizationSetting::current()->forceFill(['global_cfdi_periodicity' => $periodicity])->save();
    $donation = Donation::factory()->confirmed()->create(['received_on' => $start, 'manual_payment_method' => ManualPaymentMethod::Cash]);

    $global = app(CloseGlobalCfdiPeriod::class)->handle(CarbonImmutable::parse($asOf, 'America/Mexico_City'));

    expect(substr((string) $global?->period_start, 0, 10))->toBe($start)
        ->and(substr((string) $global?->period_end, 0, 10))->toBe($end)
        ->and($global?->donations->contains($donation))->toBeTrue();
})->with([
    ['weekly', '2026-09-28 00:15:00', '2026-09-21', '2026-09-27'],
    ['monthly', '2026-10-01 00:15:00', '2026-09-01', '2026-09-30'],
]);

it('construye RFC XAXX, S01, complemento y una partida por operación', function (): void {
    $first = Donation::factory()->confirmed()->create(['received_on' => '2026-09-22', 'amount' => '200.00', 'manual_payment_method' => ManualPaymentMethod::Cash]);
    $second = Donation::factory()->confirmed()->create(['received_on' => '2026-09-22', 'amount' => '500.00', 'manual_payment_method' => ManualPaymentMethod::BankTransfer]);
    $global = app(CloseGlobalCfdiPeriod::class)->handle(CarbonImmutable::parse('2026-09-23', 'America/Mexico_City'));
    if ($global === null) {
        throw new RuntimeException('Se esperaba una factura global.');
    }

    $draft = app(BuildGlobalCfdiDraft::class)->handle($global, $global->donations);

    expect($draft->receiverRfc)->toBe('XAXX010101000')
        ->and($draft->cfdiUse)->toBe('S01')
        ->and($draft->productCode)->toBe('01010101')
        ->and($draft->unitCode)->toBe('ACT')
        ->and($draft->paymentForm)->toBe('03')
        ->and($draft->items)->toHaveCount(2)
        ->and($draft->total)->toBe('700.00')
        ->and($global->donations->pluck('id')->sort()->values()->all())->toBe([$first->id, $second->id]);
});

it('no incluye una donación que ya tiene CFDI individual', function (): void {
    $individualDonor = Donor::factory()->withTaxProfile()->create();
    $individual = Donation::factory()->confirmed()->create(['donor_id' => $individualDonor->id, 'received_on' => '2026-09-22', 'manual_payment_method' => ManualPaymentMethod::Cash]);
    app(RequestDonationCfdi::class)->handle($individual, null);
    $public = Donation::factory()->confirmed()->create(['received_on' => '2026-09-22', 'manual_payment_method' => ManualPaymentMethod::Cash]);

    $global = app(CloseGlobalCfdiPeriod::class)->handle(CarbonImmutable::parse('2026-09-23', 'America/Mexico_City'));
    if ($global === null) {
        throw new RuntimeException('Se esperaba una factura global.');
    }

    expect($global->donations->pluck('id')->all())->toBe([$public->id]);
});

it('mantiene bloqueada una operación de forma de pago desconocida', function (): void {
    $donation = Donation::factory()->confirmed()->create(['received_on' => '2026-09-22', 'manual_payment_method' => ManualPaymentMethod::BankDeposit]);
    $global = app(CloseGlobalCfdiPeriod::class)->handle(CarbonImmutable::parse('2026-09-23', 'America/Mexico_City'));
    if ($global === null) {
        throw new RuntimeException('Se esperaba una factura global.');
    }

    expect(fn () => app(BuildGlobalCfdiDraft::class)->handle($global, $global->donations))
        ->toThrow(CfdiNotReadyException::class);
});

it('marca tardía la cobertura después de 24 horas sin impedir el reintento', function (): void {
    $donation = Donation::factory()->confirmed()->create(['confirmed_at' => now()->subHours(25), 'received_on' => now()->subDay()->toDateString()]);

    dispatch_sync(new ReconcileCfdis);

    expect($donation->fresh()?->fiscal_late_at)->not->toBeNull()
        ->and(FiscalIncident::query()->where('donation_id', $donation->id)->where('type', 'cfdi_late')->count())->toBe(1)
        ->and($donation->fresh()?->status->value)->toBe('confirmed');
});
