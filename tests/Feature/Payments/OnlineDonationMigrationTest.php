<?php

declare(strict_types=1);

use App\Enums\DonationOrigin;
use App\Enums\ManualPaymentMethod;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const DONATIONS_MIGRATION = 'database/migrations/2026_09_24_000011_add_online_origin_to_donations_table.php';

/**
 * Inserta directamente con el esquema de la Fase 1 (columna payment_method).
 *
 * @param  array<string, mixed>  $overrides
 */
function insertPhaseOneDonation(array $overrides = []): int
{
    $user = User::factory()->create();

    return (int) DB::table('donations')->insertGetId([
        'donor_id' => Donor::factory()->create()->id,
        'kind' => 'monetary',
        'payment_method' => 'bank_transfer',
        'amount' => '1500.50',
        'currency' => 'MXN',
        'received_on' => '2026-09-01',
        'status' => 'confirmed',
        'confirmed_at' => now(),
        'confirmed_by_id' => $user->id,
        'registered_by_id' => $user->id,
        'tax_receipt_requested' => false,
        'in_kind_quantity' => '1.000',
        'in_kind_unit_code' => 'H87',
        'in_kind_product_service_code' => '49101700',
        'in_kind_unit_value' => '1500.50',
        'in_kind_total_value' => '1500.50',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

it('migra los donativos de la Fase 1 a origen manual sin perder su forma de pago', function (): void {
    Artisan::call('migrate:rollback', ['--path' => DONATIONS_MIGRATION, '--force' => true]);
    expect(Schema::hasColumn('donations', 'payment_method'))->toBeTrue()
        ->and(Schema::hasColumn('donations', 'origin'))->toBeFalse();

    $cash = insertPhaseOneDonation(['payment_method' => 'cash', 'status' => 'pending', 'confirmed_at' => null, 'confirmed_by_id' => null]);
    $transfer = insertPhaseOneDonation();
    $inKind = insertPhaseOneDonation(['kind' => 'in_kind', 'payment_method' => null, 'in_kind_description' => 'Útiles escolares',
        'status' => 'pending', 'confirmed_at' => null, 'confirmed_by_id' => null]);

    Artisan::call('migrate', ['--path' => DONATIONS_MIGRATION, '--force' => true]);

    expect(Schema::hasColumn('donations', 'payment_method'))->toBeFalse()
        ->and(Donation::query()->count())->toBe(3);

    $rows = fn (int $id): Donation => Donation::query()->findOrFail($id);
    expect($rows($cash)->origin)->toBe(DonationOrigin::Manual)
        ->and($rows($cash)->manual_payment_method)->toBe(ManualPaymentMethod::Cash)
        ->and($rows($transfer)->manual_payment_method)->toBe(ManualPaymentMethod::BankTransfer)
        ->and($rows($transfer)->amount)->toBe('1500.50')
        ->and($rows($inKind)->manual_payment_method)->toBeNull()
        ->and(Donation::query()->whereNotNull('payment_id')->orWhereNull('registered_by_id')->exists())->toBeFalse();
});

it('revertir la migración restaura el esquema de la Fase 1 con sus datos manuales', function (): void {
    $id = Donation::factory()->create(['manual_payment_method' => ManualPaymentMethod::Check])->id;

    Artisan::call('migrate:rollback', ['--path' => DONATIONS_MIGRATION, '--force' => true]);

    expect(DB::table('donations')->where('id', $id)->value('payment_method'))->toBe('check');

    Artisan::call('migrate', ['--path' => DONATIONS_MIGRATION, '--force' => true]);
    expect(Donation::query()->findOrFail($id)->manual_payment_method)->toBe(ManualPaymentMethod::Check);
});

it('no revierte si ya existen donativos en línea (no pierde su origen)', function (): void {
    startFakeDonation();

    expect(fn () => Artisan::call('migrate:rollback', ['--path' => DONATIONS_MIGRATION, '--force' => true]))
        ->toThrow(RuntimeException::class, 'donativos en línea');
});

it('todas las migraciones de la Fase 2 se revierten y se vuelven a aplicar', function (): void {
    // La cadena de Fase 2 + Fase 3 + Fase 4 + Fase 6 + Fase 7 + cobertura
    // fiscal + CFDI externo y aviso contable incluye 21 migraciones. Si se usa un paso menor,
    // columna `users.receives_payment_alerts` queda intacta y la prueba falla
    // aunque la migración sea reversible y segura.
    Artisan::call('migrate:rollback', ['--step' => 21, '--force' => true]);

    foreach (['payments', 'payment_attempts', 'subscriptions', 'refunds', 'payment_disputes', 'webhook_events', 'payment_incidents', 'payment_incident_notes'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasColumn('users', 'receives_payment_alerts'))->toBeFalse()
        ->and(Schema::hasColumn('audit_logs', 'source'))->toBeFalse()
        ->and(Schema::hasColumn('donations', 'payment_method'))->toBeTrue();

    Artisan::call('migrate', ['--force' => true]);

    expect(Schema::hasTable('payments'))->toBeTrue()
        ->and(Schema::hasColumn('donations', 'origin'))->toBeTrue()
        ->and(Schema::hasColumn('audit_logs', 'source'))->toBeTrue();
});

it('la base de datos exige las reglas de origen manual y en línea', function (array $attributes, string $constraint): void {
    $user = User::factory()->create();
    $payment = Payment::factory()->succeeded()->create();

    $row = [
        'donor_id' => $payment->donor_id, 'kind' => 'monetary', 'manual_payment_method' => null, 'amount' => '100.00',
        'currency' => 'MXN', 'received_on' => '2026-09-01', 'status' => 'confirmed', 'confirmed_at' => now(),
        'confirmed_by_id' => null, 'registered_by_id' => null, 'origin' => 'online', 'payment_id' => $payment->id,
        'tax_receipt_requested' => false, 'in_kind_quantity' => '1.000', 'in_kind_unit_code' => 'H87',
        'in_kind_product_service_code' => '49101700', 'in_kind_unit_value' => '100.00', 'in_kind_total_value' => '100.00',
        'created_at' => now(), 'updated_at' => now(),
    ];
    $attributes = array_map(fn (mixed $value): mixed => $value === ':user' ? $user->id : $value, $attributes);

    expect(fn () => DB::transaction(fn () => DB::table('donations')->insert([...$row, ...$attributes])))
        ->toThrow(QueryException::class, $constraint);
})->with([
    'manual sin actor humano' => [['origin' => 'manual', 'payment_id' => null, 'manual_payment_method' => 'cash', 'status' => 'pending', 'confirmed_at' => null], 'donations_origin_manual'],
    'manual con pago en línea' => [['origin' => 'manual', 'registered_by_id' => ':user', 'confirmed_by_id' => ':user', 'manual_payment_method' => 'cash'], 'donations_origin_manual'],
    'en línea sin pago' => [['payment_id' => null], 'donations_origin_online'],
    'en línea con actor humano' => [['registered_by_id' => ':user'], 'donations_origin_online'],
    'en línea con forma de pago manual' => [['manual_payment_method' => 'cash'], 'donations_fields_by_kind'],
    'en línea en especie' => [['kind' => 'in_kind', 'in_kind_description' => 'x'], 'donations_origin_online'],
    'en línea pendiente' => [['status' => 'pending', 'confirmed_at' => null], 'donations_origin_online'],
    // Lo rechazan donations_origin_valid y donations_fields_by_kind; basta cualquiera.
    'origen desconocido' => [['origin' => 'sistema', 'status' => 'pending', 'confirmed_at' => null], 'violates check constraint'],
    'forma de pago "tarjeta"' => [['origin' => 'manual', 'registered_by_id' => ':user', 'confirmed_by_id' => ':user', 'payment_id' => null, 'manual_payment_method' => 'card'], 'donations_manual_payment_method_valid'],
]);

it('como máximo un donativo por pago', function (): void {
    $payment = startFakeDonation();
    $donation = Donation::query()->sole();

    expect(fn () => DB::transaction(fn () => DB::table('donations')->insert([
        ...collect($donation->getAttributes())->except(['id'])->all(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(Donation::query()->where('payment_id', $payment->id)->count())->toBe(1);
});
