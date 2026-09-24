<?php

declare(strict_types=1);

use App\Actions\Donations\ConfirmDonation;
use App\Actions\ExternalCfdi\AttachExternalCfdi;
use App\Actions\ExternalCfdi\RemoveExternalCfdi;
use App\Enums\AuditEvent;
use App\Enums\ManualPaymentMethod;
use App\Enums\Role;
use App\ExternalCfdi\CfdiXmlReader;
use App\Filament\Resources\Donations\Pages\ViewDonation;
use App\Filament\Resources\Donations\RelationManagers\ExternalCfdisRelationManager;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\ExternalCfdi;
use App\Models\OrganizationSetting;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * CFDI externo (docs/tecnico/cfdi-externo.md): Contabilidad emite el CFDI
 * fuera del CRM y aquí solo se adjunta como antecedente del donativo.
 */

const EXTERNAL_CFDI_MIGRATION = 'database/migrations/2026_10_01_000001_create_external_cfdis_table.php';

beforeEach(function (): void {
    Mail::fake();
    OrganizationSetting::current()->forceFill(['legal_name' => 'FUNDACION DE PRUEBA', 'rfc' => 'FPR010101AAA'])->save();
});

function confirmedForCfdi(): Donation
{
    return app(ConfirmDonation::class)->handle(Donation::factory()->create(['manual_payment_method' => ManualPaymentMethod::Cash, 'amount' => '1500.00']), userWithRole(Role::Accountant));
}

it('adjunta el XML (y PDF): lee UUID, fechas y total del XML, guarda en disco privado con nombre generado y audita', function (): void {
    $donation = confirmedForCfdi();
    $accountant = userWithRole(Role::Accountant);
    actingAs($accountant);
    $uuid = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';

    $record = app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml($uuid), "%PDF-1.4\n%prueba", '  Emitido por la contadora.  ', $accountant);

    expect($record->uuid)->toBe($uuid)
        ->and($record->issued_at->format('Y-m-d H:i'))->toBe('2026-09-20 10:15')
        ->and($record->issued_at->getTimezone()->getName())->toBe('America/Mexico_City')
        ->and($record->total)->toBe('1500.00')
        ->and($record->source)->toBe(ExternalCfdi::SOURCE_UPLOAD)
        ->and($record->uploaded_by_id)->toBe($accountant->id)
        ->and($record->notes)->toBe('Emitido por la contadora.')
        ->and($record->xml_path)->toStartWith('external-cfdi/')->toContain($uuid)->toEndWith('.xml')
        ->and(Storage::disk('local')->exists((string) $record->xml_path))->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $record->pdf_path))->toBeTrue()
        ->and(Storage::disk('public')->exists((string) $record->xml_path))->toBeFalse();

    $audit = AuditLog::query()->where('auditable_type', 'external_cfdi')->where('auditable_id', $record->id)->sole();
    expect($audit->event)->toBe(AuditEvent::Created)->and($audit->user_id)->toBe($accountant->id)
        ->and(json_encode($audit->new_values))->not->toContain('external-cfdi/');
});

it('rechaza XML con DOCTYPE o entidades (XXE) sin leerlos ni guardar nada', function (string $payload): void {
    $donation = confirmedForCfdi();

    expect(fn () => app(AttachExternalCfdi::class)->handle($donation, $payload, null, null, userWithRole(Role::Accountant)))
        ->toThrow(ValidationException::class);
    expect(ExternalCfdi::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles('external-cfdi'))->toBe([]);
})->with([
    'entidad externa' => ['<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4">&x;</cfdi:Comprobante>'],
    'bomba de entidades' => ['<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;">]><r>&lol2;</r>'],
]);

it('rechaza un DOCTYPE escondido en otra codificación (UTF-16) que el filtro de texto no ve', function (): void {
    $xml = str_replace(
        ['encoding="UTF-8"?>', '<cfdi:Comprobante '],
        ['encoding="UTF-16"?>', '<!DOCTYPE cfdi:Comprobante><cfdi:Comprobante '],
        externalCfdiXml(),
    );
    $utf16 = "\xFF\xFE".mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');

    expect(preg_match('/<!(DOCTYPE|ENTITY)/i', $utf16))->toBe(0)
        ->and(fn () => app(CfdiXmlReader::class)->read($utf16))->toThrow(ValidationException::class, 'DOCTYPE');
});

it('valida contenido, tamaño, RFC emisor, estado del donativo y UUID duplicado', function (): void {
    $donation = confirmedForCfdi();
    $accountant = userWithRole(Role::Accountant);
    $attach = fn (string $xml, ?string $pdf = null, ?Donation $target = null) => fn () => app(AttachExternalCfdi::class)
        ->handle($target ?? $donation, $xml, $pdf, null, $accountant);

    expect($attach('esto no es xml'))->toThrow(ValidationException::class)
        ->and($attach('<?xml version="1.0"?><Comprobante Fecha="2026-09-20T10:15:00"/>'))->toThrow(ValidationException::class, 'no es un CFDI')
        ->and($attach(str_replace('UUID="', 'UUIDX="', externalCfdiXml())))->toThrow(ValidationException::class, 'no está timbrado')
        ->and($attach(externalCfdiXml().str_repeat(' ', 2 * 1024 * 1024)))->toThrow(ValidationException::class)
        ->and($attach(externalCfdiXml(), 'MZ ejecutable'))->toThrow(ValidationException::class, 'PDF')
        ->and($attach(externalCfdiXml(), '%PDF-'.str_repeat('a', AttachExternalCfdi::MAX_PDF_BYTES)))->toThrow(ValidationException::class, 'PDF')
        ->and($attach(externalCfdiXml(issuerRfc: 'OTR010101AAA')))->toThrow(ValidationException::class, 'RFC emisor')
        ->and($attach(externalCfdiXml(), null, Donation::factory()->create()))->toThrow(ValidationException::class, 'confirmados');

    $uuid = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';
    app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml($uuid), null, null, $accountant);
    expect($attach(externalCfdiXml($uuid)))->toThrow(ValidationException::class, 'ya está adjunto')
        ->and(ExternalCfdi::query()->count())->toBe(1);
});

it('reemplazar y retirar: el anterior queda retirado con motivo y sus archivos se conservan; no se borra nada', function (): void {
    $donation = confirmedForCfdi();
    $accountant = userWithRole(Role::Accountant);
    $first = app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml(), null, null, $accountant);

    $second = app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml(), null, null, $accountant, replaces: $first);
    expect($first->refresh()->isActive())->toBeFalse()->and($first->removal_reason)->toContain($second->uuid)
        ->and(Storage::disk('local')->exists((string) $first->xml_path))->toBeTrue();

    expect(fn () => app(RemoveExternalCfdi::class)->handle($second, 'no', $accountant))->toThrow(ValidationException::class);
    app(RemoveExternalCfdi::class)->handle($second, 'Se adjuntó el XML de otro donativo.', $accountant);

    expect($second->refresh()->removed_by_id)->toBe($accountant->id)
        ->and(ExternalCfdi::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('auditable_type', 'external_cfdi')->where('event', AuditEvent::Discarded->value)->count())->toBe(2)
        ->and(fn () => app(RemoveExternalCfdi::class)->handle($second, 'Otra vez el mismo', $accountant))->toThrow(ValidationException::class);
});

it('permisos: Administrador y Contador adjuntan; Coordinador solo consulta y descarga; Solo lectura nada', function (): void {
    $donation = confirmedForCfdi();
    $record = app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml(), "%PDF-1.4\n%prueba", null, userWithRole(Role::Administrator));

    expect(fn () => app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml(), null, null, userWithRole(Role::FundraisingCoordinator)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(RemoveExternalCfdi::class)->handle($record, 'Motivo suficiente', userWithRole(Role::FundraisingCoordinator)))
        ->toThrow(AuthorizationException::class);

    get(route('external-cfdi.files', [$record, 'xml']))->assertRedirect();

    actingAs(userWithRole(Role::ReadOnly));
    get(route('external-cfdi.files', [$record, 'xml']))->assertForbidden();
    expect(ExternalCfdisRelationManager::canViewForRecord($donation, ViewDonation::class))->toBeFalse();

    actingAs(userWithRole(Role::FundraisingCoordinator));
    $response = get(route('external-cfdi.files', [$record, 'xml']))->assertOk()
        ->assertHeader('Content-Type', 'application/xml')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('attachment');
    get(route('external-cfdi.files', [$record, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    get('/admin/external-cfdi-files/'.$record->id.'/exe')->assertNotFound();
    Livewire::test(ExternalCfdisRelationManager::class, ['ownerRecord' => $donation, 'pageClass' => ViewDonation::class])
        ->assertActionHidden(TestAction::make('attach')->table())
        ->assertActionHidden(TestAction::make('remove')->table($record))
        ->assertActionVisible(TestAction::make('downloadXml')->table($record));
});

it('pantalla del donativo: "Recibo de donación" y "CFDI externo" separados; nunca ofrece emitir ni timbrar', function (): void {
    $donation = confirmedForCfdi();
    $accountant = userWithRole(Role::Accountant);
    actingAs($accountant);

    get("/admin/donations/{$donation->id}")->assertOk()
        ->assertSee('Recibo de donación')->assertSee('Procesamiento contable')
        ->assertDontSee('Emitir CFDI')->assertDontSee('Timbrar')->assertDontSee('Reintentar timbrado');
    expect(ExternalCfdisRelationManager::canViewForRecord($donation, ViewDonation::class))->toBeTrue();

    Livewire::test(ExternalCfdisRelationManager::class, ['ownerRecord' => $donation, 'pageClass' => ViewDonation::class])
        ->callAction(TestAction::make('attach')->table(), [
            'xml' => UploadedFile::fake()->createWithContent('cualquier-nombre.xml', externalCfdiXml('AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE')),
            'notes' => 'Adjuntado desde la pantalla.',
        ])
        ->assertHasNoFormErrors();

    $record = ExternalCfdi::query()->sole();
    expect(strtoupper($record->uuid))->toBe('AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE')->and($record->xml_path)->not->toContain('cualquier-nombre');

    Livewire::test(ExternalCfdisRelationManager::class, ['ownerRecord' => $donation, 'pageClass' => ViewDonation::class])
        ->callAction(TestAction::make('remove')->table($record), ['reason' => 'Se adjuntó por error.']);
    expect($record->refresh()->isActive())->toBeFalse();
});

it('la migración conserva como antecedentes los CFDI que el CRM llegó a timbrar (individuales, cancelados y globales)', function (): void {
    $donation = confirmedForCfdi();
    $global = confirmedForCfdi();
    Artisan::call('migrate:rollback', ['--path' => EXTERNAL_CFDI_MIGRATION, '--force' => true]);

    $cfdi = fn (string $status, array $extra = []): int => DB::table('cfdis')->insertGetId([
        'donation_id' => $donation->id, 'provider' => 'facturapi', 'uuid' => strtolower((string) Str::uuid()), 'status' => $status,
        'total' => '1500.00', 'idempotency_key' => (string) Str::uuid(), 'requested_at' => now(), 'stamped_at' => '2026-09-10 12:00:00',
        'xml_path' => 'cfdi/2026/09/legado.xml', 'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
    $stampedId = $cfdi('stamped');
    $cancelledId = $cfdi('cancelled', ['cancelled_at' => '2026-09-11 12:00:00', 'cancellation_motive' => '02', 'cancellation_requested_at' => '2026-09-11 11:00:00']);
    $globalId = DB::table('global_cfdis')->insertGetId([
        'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'periodicity' => 'monthly', 'provider' => 'facturapi', 'status' => 'stamped',
        'total' => '1500.00', 'idempotency_key' => 'global:2026-09', 'uuid' => strtolower((string) Str::uuid()), 'requested_at' => now(),
        'stamped_at' => '2026-10-01 09:00:00', 'xml_path' => 'cfdi/global.xml', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('donation_global_cfdi')->insert(['global_cfdi_id' => $globalId, 'donation_id' => $global->id, 'operation_number' => 'OP-1', 'created_at' => now(), 'updated_at' => now()]);

    Artisan::call('migrate', ['--path' => EXTERNAL_CFDI_MIGRATION, '--force' => true]);

    $legacy = ExternalCfdi::query()->where('source', ExternalCfdi::SOURCE_CRM_LEGACY)->orderBy('id')->get()->all();
    expect($legacy)->toHaveCount(3);
    [$fromStamped, $fromCancelled, $fromGlobal] = $legacy;
    expect($fromStamped->legacy_cfdi_id)->toBe($stampedId)->and($fromStamped->isActive())->toBeTrue()
        ->and($fromStamped->xml_path)->toBe('cfdi/2026/09/legado.xml')
        ->and($fromCancelled->legacy_cfdi_id)->toBe($cancelledId)->and($fromCancelled->isActive())->toBeFalse()
        ->and($fromCancelled->removal_reason)->toContain('Cancelado')
        ->and($fromGlobal->legacy_global_cfdi_id)->toBe($globalId)->and($fromGlobal->donation_id)->toBe($global->id)
        ->and(DB::table('cfdis')->count())->toBe(2);

    app(AttachExternalCfdi::class)->handle($donation, externalCfdiXml(), null, null, userWithRole(Role::Accountant));
    expect(fn () => Artisan::call('migrate:rollback', ['--path' => EXTERNAL_CFDI_MIGRATION, '--force' => true]))
        ->toThrow(RuntimeException::class, 'cargados por usuarios');
});
