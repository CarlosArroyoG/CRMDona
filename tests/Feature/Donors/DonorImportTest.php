<?php

declare(strict_types=1);

use App\Actions\Donors\ImportDonorRow;
use App\Enums\AuditEvent;
use App\Enums\DonorOrigin;
use App\Enums\DonorType;
use App\Enums\Role;
use App\Filament\Imports\DonorImporter;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Models\AuditLog;
use App\Models\Donor;
use App\Models\Import;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;

/**
 * @param  array<string, mixed>  $row
 */
function importRow(array $row, ?User $actor = null, bool $consent = false, ?string $batchTag = null): Donor
{
    return app(ImportDonorRow::class)->handle($row, $actor ?? userWithRole(Role::FundraisingCoordinator), $consent, $batchTag);
}

/**
 * Ejecuta un bloque de la carga como lo hace la cola, con el mapeo de
 * columnas que propone Filament (encabezado CSV → columna).
 *
 * @param  list<array<string, string>>  $rows
 * @param  array<string, mixed>  $options
 */
function runDonorImport(User $actor, array $rows, array $options = []): Import
{
    $headers = array_keys($rows[0]);
    $columnMap = [];
    foreach (DonorImporter::getColumns() as $column) {
        $header = collect($headers)->first(fn (string $header): bool => in_array(mb_strtolower($header), $column->getGuesses(), true));
        if ($header !== null) {
            $columnMap[$column->getName()] = $header;
        }
    }

    $import = Import::query()->create([
        'file_name' => 'donantes.csv',
        'file_path' => 'donantes.csv',
        'importer' => DonorImporter::class,
        'total_rows' => count($rows),
        'user_id' => $actor->id,
    ]);

    (new ImportCsv($import, base64_encode(serialize($rows)), $columnMap, $options))->handle();

    return $import->refresh();
}

it('registra un donante por fila con el origen, quien lo cargó, etiquetas y fecha', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);

    $donor = importRow([
        'type' => 'Física', 'first_name' => ' María ', 'last_name' => 'López', 'email' => 'Maria.Lopez@Example.com',
        'birth_date' => '15/03/1980', 'tags' => 'Padrino; Evento 2026', 'phone' => '777 123 4567',
    ], $actor);

    expect($donor->type)->toBe(DonorType::Individual)
        ->and($donor->display_name)->toBe('María López')
        ->and($donor->email)->toBe('maria.lopez@example.com')
        ->and($donor->birth_date?->toDateString())->toBe('1980-03-15')
        ->and($donor->origin)->toBe(DonorOrigin::CsvImport)
        ->and($donor->registered_by_id)->toBe($actor->id)
        ->and($donor->privacy_notice_accepted_at)->toBeNull()
        ->and($donor->tags()->orderBy('name')->pluck('name')->all())->toBe(['Evento 2026', 'Padrino']);
});

it('registra personas morales y acepta fechas ISO', function (): void {
    $donor = importRow(['type' => 'moral', 'legal_name' => 'Aceros del Sur S.A. de C.V.', 'contact_name' => 'Luis Mora']);
    $person = importRow(['first_name' => 'Ana', 'last_name' => 'Ruiz', 'birth_date' => '1990-12-31']);

    expect($donor->type)->toBe(DonorType::Organization)
        ->and($donor->display_name)->toBe('Aceros del Sur S.A. de C.V.')
        ->and($person->type)->toBe(DonorType::Individual)
        ->and($person->birth_date?->toDateString())->toBe('1990-12-31');
});

it('omite la fila si el correo ya existe y no modifica al donante existente', function (): void {
    $existing = Donor::factory()->create(['email' => 'ana@example.com', 'first_name' => 'Ana', 'phone' => null]);

    expect(fn () => importRow(['first_name' => 'Otra', 'last_name' => 'Persona', 'email' => 'ANA@example.com ', 'phone' => '777 000 0000']))
        ->toThrow(ValidationException::class, 'Ya existe un donante con el correo ana@example.com');

    expect(Donor::query()->count())->toBe(1)
        ->and($existing->refresh()->first_name)->toBe('Ana')
        ->and($existing->phone)->toBeNull();
});

it('solo respeta "acepta comunicaciones" si quien importa confirma el consentimiento', function (): void {
    $sinConfirmar = importRow(['first_name' => 'Uno', 'last_name' => 'A', 'accepts_communications' => 'sí']);
    $confirmado = importRow(['first_name' => 'Dos', 'last_name' => 'B', 'accepts_communications' => 'SI'], consent: true);
    $dijoNo = importRow(['first_name' => 'Tres', 'last_name' => 'C', 'accepts_communications' => 'no'], consent: true);

    expect($sinConfirmar->accepts_communications)->toBeFalse()
        ->and($confirmado->accepts_communications)->toBeTrue()
        ->and($confirmado->communications_consent_updated_at)->not->toBeNull()
        ->and($dijoNo->accepts_communications)->toBeFalse();
});

it('rechaza filas inválidas con mensajes en español', function (array $row, string $message): void {
    expect(fn () => importRow($row))->toThrow(ValidationException::class, $message);
})->with([
    'tipo desconocido' => [['type' => 'otro', 'first_name' => 'A', 'last_name' => 'B'], 'Tipo de persona no reconocido'],
    'fecha inválida' => [['first_name' => 'A', 'last_name' => 'B', 'birth_date' => '31/02/1980'], 'Fecha de nacimiento inválida'],
    'fecha de dos dígitos' => [['first_name' => 'A', 'last_name' => 'B', 'birth_date' => '15/03/80'], 'Fecha de nacimiento inválida'],
    'sin apellido' => [['first_name' => 'A'], 'apellido paterno'],
    'moral sin razón social' => [['type' => 'moral', 'contact_name' => 'X'], 'razón social'],
    'correo inválido' => [['first_name' => 'A', 'last_name' => 'B', 'email' => 'no-es-correo'], 'correo'],
    'consentimiento ambiguo' => [['first_name' => 'A', 'last_name' => 'B', 'accepts_communications' => 'tal vez'], 'Acepta comunicaciones'],
]);

it('reutiliza etiquetas sin importar mayúsculas y agrega la etiqueta de la carga', function (): void {
    $padrino = Tag::query()->create(['name' => 'Padrino']);

    $donor = importRow(['first_name' => 'A', 'last_name' => 'B', 'tags' => 'padrino, PADRINO'], batchTag: 'Carga septiembre 2026');

    expect($donor->tags()->pluck('tags.id')->all())->toContain($padrino->id)
        ->and($donor->tags()->count())->toBe(2)
        ->and(Tag::query()->where('name', 'Carga septiembre 2026')->exists())->toBeTrue();
});

it('solo Administrador y Coordinador cargan donantes', function (): void {
    expect(fn () => importRow(['first_name' => 'A', 'last_name' => 'B'], userWithRole(Role::Accountant)))
        ->toThrow(AuthorizationException::class);

    actingAs(userWithRole(Role::Accountant));
    Livewire::test(ListDonors::class)->assertActionHidden(TestAction::make('import')->table());

    actingAs(userWithRole(Role::FundraisingCoordinator));
    Livewire::test(ListDonors::class)->assertActionVisible(TestAction::make('import')->table());
});

it('procesa el archivo en la cola: importa las válidas y guarda el motivo de las rechazadas', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    Donor::factory()->create(['email' => 'repetido@example.com']);

    $import = runDonorImport($actor, [
        ['nombre' => 'María', 'apellido_paterno' => 'López', 'correo' => 'maria@example.com', 'acepta_comunicaciones' => 'sí', 'etiquetas' => 'Padrino'],
        ['nombre' => 'Pedro', 'apellido_paterno' => 'Gómez', 'correo' => 'repetido@example.com', 'acepta_comunicaciones' => 'sí', 'etiquetas' => ''],
        ['nombre' => 'Sin', 'apellido_paterno' => '', 'correo' => 'sin@example.com', 'acepta_comunicaciones' => 'no', 'etiquetas' => ''],
        ['nombre' => 'maría', 'apellido_paterno' => 'Duplicada', 'correo' => 'MARIA@example.com', 'acepta_comunicaciones' => 'no', 'etiquetas' => ''],
    ], ['consent_confirmed' => true, 'batch_tag' => 'Carga prueba']);

    $maria = Donor::query()->where('email', 'maria@example.com')->sole();

    expect($import->processed_rows)->toBe(4)
        ->and($import->successful_rows)->toBe(1)
        ->and($import->failedRows()->pluck('validation_error')->implode(' | '))
        ->toContain('Ya existe un donante con el correo repetido@example.com')
        ->toContain('apellido paterno')
        ->toContain('Ya existe un donante con el correo maria@example.com')
        ->and($maria->accepts_communications)->toBeTrue()
        ->and($maria->tags()->orderBy('name')->pluck('name')->all())->toBe(['Carga prueba', 'Padrino']);

    // La bitácora registra quién cargó al donante y su consentimiento declarado.
    $audit = AuditLog::query()->where('auditable_type', 'donor')->where('auditable_id', $maria->id)->where('event', AuditEvent::Created)->sole();
    expect($audit->user_id)->toBe($actor->id)
        ->and($audit->new_values)->toMatchArray(['accepts_communications' => true, 'origin' => 'csv_import']);
});

it('reconoce los encabezados de la exportación de donantes', function (): void {
    $import = runDonorImport(userWithRole(Role::Administrator), [
        ['Tipo' => 'Persona moral', 'Razón social' => 'Fundación Amiga A.C.', 'Correo electrónico' => 'contacto@example.org', 'Acepta comunicaciones' => 'Sí'],
    ], ['consent_confirmed' => true]);

    expect($import->successful_rows)->toBe(1)
        ->and(Donor::query()->where('legal_name', 'Fundación Amiga A.C.')->value('accepts_communications'))->toBeTrue();
});

it('purga las cargas y sus filas rechazadas a los 7 días', function (): void {
    $import = runDonorImport(userWithRole(Role::Administrator), [
        ['nombre' => 'Sin', 'apellido_paterno' => ''],
    ]);
    expect($import->failedRows()->count())->toBe(1);

    travel(Import::RETENTION_DAYS + 1)->days();
    Artisan::call('model:prune', ['--model' => [Import::class]]);

    expect(Import::query()->count())->toBe(0)
        ->and(DB::table('failed_import_rows')->count())->toBe(0);
});

it('ofrece una plantilla CSV con las columnas y ejemplos que se cargan tal cual', function (): void {
    $csv = DonorImporter::templateCsv();
    expect($csv)->toStartWith("\u{FEFF}tipo_persona,nombre,apellido_paterno,apellido_materno,razon_social,persona_contacto,correo,telefono,fecha_nacimiento,etiquetas,notas,acepta_comunicaciones");

    $lines = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
    /** @var list<string> $headers */
    $headers = array_shift($lines);
    $rows = array_map(fn (array $values): array => array_combine($headers, array_map('strval', $values)), $lines);

    $import = runDonorImport(userWithRole(Role::FundraisingCoordinator), $rows, ['consent_confirmed' => true]);

    expect($rows)->toHaveCount(3)
        ->and($import->successful_rows)->toBe(3)
        ->and(Donor::query()->where('legal_name', 'Aceros del Sur S.A. de C.V.')->value('type'))->toBe(DonorType::Organization);
});

it('descarga la plantilla desde la lista de donantes', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));

    Livewire::test(ListDonors::class)
        ->callAction(TestAction::make('downloadImportTemplate')->table())
        ->assertFileDownloaded('plantilla-donantes.csv');

    actingAs(userWithRole(Role::Accountant));
    Livewire::test(ListDonors::class)->assertActionHidden(TestAction::make('downloadImportTemplate')->table());
});
