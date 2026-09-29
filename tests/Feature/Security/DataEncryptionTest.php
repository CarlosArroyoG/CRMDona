<?php

declare(strict_types=1);

use App\Actions\Donors\FindDonorDuplicates;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\DonorTaxProfile;
use App\Support\BlindIndex;
use App\Support\SensitiveColumns;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

const ENCRYPTION_MIGRATION = 'database/migrations/2026_10_07_000001_encrypt_sensitive_donor_fields.php';

/**
 * Donante con datos fiscales, teléfono y notas conocidos.
 */
function donorWithSensitiveData(string $rfc = 'ZZAB800101AB1'): Donor
{
    $donor = Donor::factory()->create(['phone' => '777 123 4567', 'notes' => 'Prefiere que le llamen por la tarde']);
    $donor->taxProfile()->create([
        'rfc' => $rfc, 'tax_name' => 'NOMBRE FICTICIO', 'tax_regime' => TaxRegime::Wages,
        'tax_postal_code' => '62000', 'cfdi_use' => null,
    ]);

    return $donor->refresh();
}

it('en la base los datos fiscales, el teléfono y las notas quedan cifrados; la aplicación los lee normal', function (): void {
    $donor = donorWithSensitiveData();

    $rawProfile = DB::table('donor_tax_profiles')->where('donor_id', $donor->id)->sole();
    $rawDonor = DB::table('donors')->where('id', $donor->id)->sole();
    $dump = json_encode([$rawProfile, $rawDonor], JSON_THROW_ON_ERROR);

    foreach (['ZZAB800101AB1', 'NOMBRE FICTICIO', '62000', '777 123 4567', 'llamen por la tarde'] as $plain) {
        expect(str_contains($dump, $plain))->toBeFalse("Legible en la base: {$plain}");
    }

    expect(Crypt::decryptString($rawProfile->rfc))->toBe('ZZAB800101AB1')
        ->and($rawProfile->rfc_hash)->toBe(BlindIndex::rfc('zzab800101ab1'))
        ->and($donor->phone)->toBe('777 123 4567')
        ->and($donor->notes)->toBe('Prefiere que le llamen por la tarde')
        ->and($donor->taxProfile?->rfc)->toBe('ZZAB800101AB1')
        ->and($donor->taxProfile?->tax_regime)->toBe(TaxRegime::Wages);
});

it('detecta RFC duplicados y busca el RFC completo por su huella, sin descifrar', function (): void {
    $donor = donorWithSensitiveData('ZZAB800101AB1');

    expect(app(FindDonorDuplicates::class)->handle(null, ' zzab800101ab1 ')->pluck('id')->all())->toBe([$donor->id]);

    actingAs(userWithRole(Role::Administrator));
    Livewire::test(ListDonors::class)->searchTable('ZZAB800101AB1')->assertCanSeeTableRecords([$donor]);
    // Un fragmento del RFC ya no encuentra nada: el texto no es legible en la base.
    Livewire::test(ListDonors::class)->searchTable('ZZAB80')->assertCanNotSeeTableRecords([$donor]);
});

it('guardar sin cambios no deja una modificación falsa en la bitácora', function (): void {
    $donor = donorWithSensitiveData();
    $profile = DonorTaxProfile::query()->where('donor_id', $donor->id)->sole();
    $before = AuditLog::query()->count();

    $profile->fill(['rfc' => 'ZZAB800101AB1', 'tax_regime' => TaxRegime::Wages, 'tax_postal_code' => '62000'])->save();
    $donor->fill(['phone' => '777 123 4567'])->save();

    expect(AuditLog::query()->count())->toBe($before);
});

it('la migración cifra los datos existentes y la reversión los devuelve legibles', function (): void {
    Artisan::call('migrate:rollback', ['--path' => ENCRYPTION_MIGRATION, '--force' => true]);

    $userId = userWithRole(Role::Administrator)->id;
    $donorId = DB::table('donors')->insertGetId([
        'type' => 'individual', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'phone' => '777 000 1111', 'notes' => 'Nota previa',
        'accepts_communications' => false, 'communications_consent_updated_at' => now(), 'registered_by_id' => $userId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('donor_tax_profiles')->insert([
        'donor_id' => $donorId, 'rfc' => 'ZZRA900101AA1', 'tax_name' => 'ANA RUIZ', 'tax_regime' => '605',
        'tax_postal_code' => '62000', 'created_at' => now(), 'updated_at' => now(),
    ]);

    Artisan::call('migrate', ['--path' => ENCRYPTION_MIGRATION, '--force' => true]);

    $raw = DB::table('donor_tax_profiles')->where('donor_id', $donorId)->sole();
    $donor = Donor::query()->findOrFail($donorId);
    expect($raw->rfc)->not->toBe('ZZRA900101AA1')
        ->and($raw->rfc_hash)->toBe(BlindIndex::rfc('ZZRA900101AA1'))
        ->and(DB::table('donors')->where('id', $donorId)->value('phone'))->not->toBe('777 000 1111')
        ->and($donor->phone)->toBe('777 000 1111')
        ->and($donor->notes)->toBe('Nota previa')
        ->and($donor->taxProfile?->tax_regime)->toBe(TaxRegime::Wages);

    Artisan::call('migrate:rollback', ['--path' => ENCRYPTION_MIGRATION, '--force' => true]);
    expect(DB::table('donor_tax_profiles')->where('donor_id', $donorId)->value('rfc'))->toBe('ZZRA900101AA1')
        ->and(DB::table('donors')->where('id', $donorId)->value('phone'))->toBe('777 000 1111');

    Artisan::call('migrate', ['--path' => ENCRYPTION_MIGRATION, '--force' => true]);
});

it('app:security-check detecta un usuario de base con privilegios de más y el modo depuración', function (): void {
    config(['app.debug' => true]);

    // Las pruebas usan el superusuario local: el comando debe señalarlo.
    Artisan::call('app:security-check');
    $output = Artisan::output();

    expect($output)->toContain('APP_DEBUG apagado')->toContain('Usuario de la base sin superusuario')->toContain('CORREGIR');
});

it('el script de mínimo privilegio protege la bitácora, los donativos y las migraciones', function (): void {
    $sql = (string) file_get_contents(base_path('docker/postgres/least-privilege.sql'));

    expect($sql)->toContain('NOSUPERUSER NOCREATEDB NOCREATEROLE')
        ->toContain('REVOKE UPDATE, DELETE ON audit_logs FROM crm_app')
        ->toContain('REVOKE DELETE ON donations FROM crm_app')
        ->toContain('REVOKE INSERT, UPDATE, DELETE ON migrations FROM crm_app')
        ->toContain('REVOKE ALL ON SCHEMA public FROM PUBLIC')
        // Ninguna contraseña escrita en el script: solo %L con la variable de psql.
        ->and(preg_match("/PASSWORD\\s+'[^%]/", $sql))->toBe(0);
});

it('cifra lo que el contenedor anterior haya guardado legible durante el despliegue', function (): void {
    $donor = donorWithSensitiveData();
    // Así escribiría el código anterior al cifrado: texto legible y sin huella.
    DB::table('donors')->where('id', $donor->id)->update(['phone' => '777 999 0000']);
    DB::table('donor_tax_profiles')->where('donor_id', $donor->id)->update(['tax_name' => 'NOMBRE LEGIBLE', 'rfc_hash' => null]);

    expect(SensitiveColumns::pendingCount())->toBe(2);

    Artisan::call('app:encrypt-legacy-data');

    expect(SensitiveColumns::pendingCount())->toBe(0)
        ->and(DB::table('donors')->where('id', $donor->id)->value('phone'))->toStartWith(SensitiveColumns::CIPHER_PREFIX)
        ->and($donor->refresh()->phone)->toBe('777 999 0000')
        ->and($donor->taxProfile?->tax_name)->toBe('NOMBRE LEGIBLE')
        ->and(app(FindDonorDuplicates::class)->handle(null, 'ZZAB800101AB1')->pluck('id')->all())->toBe([$donor->id]);

    // Repetirlo no cambia nada.
    Artisan::call('app:encrypt-legacy-data');
    expect(Artisan::output())->toContain('todo estaba cifrado');
});
