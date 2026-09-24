<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Filament\Resources\Programs\Pages\ListPrograms;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Export;
use App\Models\User;
use App\Policies\ExportPolicy;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
});

it('exporta donativos a CSV con BOM, acentos e importes exactos', function (): void {
    $user = userWithRole(Role::Accountant);
    actingAs($user);
    Donation::factory()->create([
        'donor_id' => Donor::factory()->create(['first_name' => 'Begoña', 'last_name' => 'Álvarez', 'second_last_name' => null])->id,
        'amount' => '1234.50',
    ]);

    Livewire::test(ListDonations::class)->callAction(TestAction::make('export')->table());

    $export = Export::query()->where('user_id', $user->id)->sole();
    $csv = exportedCsv($export);

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Begoña Álvarez')
        ->toContain('1234.50')
        ->toContain('Por confirmar')
        ->toContain('Importe o valor (MXN)')
        ->and(Storage::disk('local')->exists($export->getFileDirectory().'/'.$export->file_name.'.xlsx'))->toBeTrue();
});

it('neutraliza fórmulas de hoja de cálculo en los datos exportados (CWE-1236)', function (): void {
    $user = userWithRole(Role::Administrator);
    actingAs($user);
    Donor::factory()->create(['first_name' => '=1+1', 'last_name' => '@SUMA(A1)', 'second_last_name' => null, 'phone' => '+52 777 123 4567']);

    Livewire::test(ListDonors::class)->callAction(TestAction::make('export')->table());

    $csv = exportedCsv(Export::query()->where('user_id', $user->id)->sole());
    expect($csv)->toContain("'=1+1")->toContain("'@SUMA(A1)")->toContain('+52 777 123 4567')
        ->and(str_contains((string) $csv, "'+52"))->toBeFalse()
        ->and(preg_match('/(^|,)(=1\+1|@SUMA)/m', (string) $csv))->toBe(0);
});

it('respeta los filtros aplicados al exportar', function (): void {
    $user = userWithRole(Role::Administrator);
    actingAs($user);
    Donation::factory()->create(['amount' => '111.11']);
    Donation::factory()->confirmed()->create(['amount' => '222.22']);

    Livewire::test(ListDonations::class)
        ->filterTable('status', 'confirmed')
        ->callAction(TestAction::make('export')->table());

    $csv = exportedCsv(Export::query()->sole());
    expect($csv)->toContain('222.22');
    expect($csv)->not->toContain('111.11');
});

it('escribe los importes como números en XLSX', function (): void {
    $user = userWithRole(Role::Administrator);
    actingAs($user);
    Donation::factory()->create(['amount' => '1500.50']);

    Livewire::test(ListDonations::class)->callAction(TestAction::make('export')->table());

    $export = Export::query()->sole();
    $path = Storage::disk('local')->path($export->getFileDirectory().'/'.$export->file_name.'.xlsx');
    $zip = new ZipArchive;
    $zip->open($path);
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheet)->toContain('<v>1500.5</v>');
});

it('no permite a Solo lectura exportar donantes ni donativos, pero sí programas', function (): void {
    actingAs(userWithRole(Role::ReadOnly));

    Livewire::test(ListDonors::class)->assertActionHidden(TestAction::make('export')->table());
    Livewire::test(ListDonations::class)->assertActionHidden(TestAction::make('export')->table());
    Livewire::test(ListPrograms::class)->assertActionVisible(TestAction::make('export')->table());
});

it('solo deja descargar el archivo a quien lo generó y siga activo', function (): void {
    $owner = userWithRole(Role::Accountant);
    $export = new Export(['file_disk' => 'local', 'exporter' => 'x', 'total_rows' => 0]);
    $export->forceFill(['user_id' => $owner->id]);

    $policy = new ExportPolicy;
    expect($policy->view($owner, $export))->toBeTrue()
        ->and($policy->view(userWithRole(Role::Administrator), $export))->toBeFalse();

    $owner->forceFill(['deactivated_at' => now()])->save();
    expect($policy->view($owner, $export))->toBeFalse();
});

it('elimina a los 7 días solo el archivo exportado, no los datos', function (): void {
    $user = userWithRole(Role::Administrator);
    actingAs($user);
    Donation::factory()->create();
    Livewire::test(ListDonations::class)->callAction(TestAction::make('export')->table());
    $export = Export::query()->sole();
    $directory = $export->getFileDirectory();

    Carbon::setTestNow(now()->addDays(Export::RETENTION_DAYS + 1));
    Artisan::call('model:prune', ['--model' => [Export::class]]);
    Carbon::setTestNow();

    expect(Export::query()->count())->toBe(0)
        ->and(Storage::disk('local')->directoryExists($directory))->toBeFalse()
        ->and(Donation::query()->count())->toBe(1)
        ->and(User::query()->count())->toBeGreaterThan(0);
});
