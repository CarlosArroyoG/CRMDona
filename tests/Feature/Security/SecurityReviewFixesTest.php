<?php

declare(strict_types=1);

use App\Actions\Communications\QueueCommunication;
use App\Actions\Donors\PrepareBirthdayWhatsApp;
use App\Actions\Mail\UpdateMailSettings;
use App\Communications\BulkAudience;
use App\Enums\CommunicationKind;
use App\Enums\CommunicationStatus;
use App\Enums\DonationStatus;
use App\Enums\DonorOrigin;
use App\Enums\Role;
use App\Jobs\SendBirthdayGreetings;
use App\Models\Communication;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Donor;
use App\Models\Export;
use App\Models\ExternalCfdi;
use App\Models\Import;
use App\Models\User;
use App\Policies\DonationReceiptPolicy;
use App\Policies\ExportPolicy;
use App\Policies\ExternalCfdiPolicy;
use App\Policies\ImportPolicy;
use App\Support\ProductionSafety;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\travelTo;

/*
 * Correcciones de la revisión de seguridad del 2026-09-28.
 */

/**
 * @param  array<string, mixed>  $attributes
 */
function publicPageDonor(array $attributes = []): Donor
{
    return Donor::factory()->create([
        'origin' => DonorOrigin::PublicPage,
        'registered_by_id' => null,
        'accepts_communications' => true,
        ...$attributes,
    ]);
}

it('un donante de la página pública sin donativo confirmado no recibe envíos masivos', function (): void {
    $sinPagar = publicPageDonor();
    Donation::factory()->create(['donor_id' => $sinPagar->id, 'status' => DonationStatus::Pending]);
    $conPago = publicPageDonor();
    Donation::factory()->confirmed()->create(['donor_id' => $conPago->id]);
    $manual = Donor::factory()->create(['accepts_communications' => true]);

    $audience = new BulkAudience;

    expect($audience->recipients()->orderBy('id')->pluck('id')->all())->toBe([$conPago->id, $manual->id])
        ->and($audience->summary()['without_consent'])->toBe(1)
        ->and($sinPagar->hasVerifiedCommunicationsConsent())->toBeFalse()
        ->and($conPago->hasVerifiedCommunicationsConsent())->toBeTrue()
        ->and($manual->hasVerifiedCommunicationsConsent())->toBeTrue();
});

it('la felicitación de cumpleaños tampoco llega a consentimientos sin verificar', function (): void {
    Mail::fake();
    travelTo(CarbonImmutable::parse('2026-05-10 09:00', 'America/Mexico_City'));
    $sinPagar = publicPageDonor(['birth_date' => '1990-05-10']);
    $conPago = publicPageDonor(['birth_date' => '1985-05-10']);
    Donation::factory()->confirmed()->create(['donor_id' => $conPago->id]);

    (new SendBirthdayGreetings)->handle(app(QueueCommunication::class));

    expect(Communication::query()->where('kind', CommunicationKind::Birthday)->pluck('donor_id')->all())->toBe([$conPago->id]);

    // Si algo la encolara de todos modos, el envío queda "No enviado" con el motivo.
    $forced = app(QueueCommunication::class)->handle(CommunicationKind::Birthday, $sinPagar, 'birthday:forzado');
    expect($forced->status)->toBe(CommunicationStatus::Skipped)
        ->and($forced->skip_reason)->toBe(QueueCommunication::UNVERIFIED_CONSENT);
});

it('no ofrece preparar WhatsApp a un consentimiento sin verificar', function (): void {
    $donor = publicPageDonor(['phone' => '777 123 4567']);

    expect(PrepareBirthdayWhatsApp::unavailableReason($donor))->toBe(QueueCommunication::UNVERIFIED_CONSENT);

    Donation::factory()->confirmed()->create(['donor_id' => $donor->id]);
    expect(PrepareBirthdayWhatsApp::unavailableReason($donor->refresh()))->toBeNull();
});

it('las descargas fuera del panel exigen el MFA configurado', function (): void {
    $withMfa = userWithRole(Role::Administrator);
    $withoutMfa = User::factory()->withRole(Role::Administrator)->withoutMultiFactorAuthentication()->create();
    $receipt = (new DonationReceipt)->forceFill(['pdf_path' => 'receipts/x.pdf']);
    $cfdi = new ExternalCfdi;
    $export = (new Export)->forceFill(['user_id' => $withoutMfa->id]);
    $import = (new Import)->forceFill(['user_id' => $withoutMfa->id]);

    expect((new DonationReceiptPolicy)->download($withMfa, $receipt))->toBeTrue()
        ->and((new DonationReceiptPolicy)->download($withoutMfa, $receipt))->toBeFalse()
        ->and((new ExternalCfdiPolicy)->download($withMfa, $cfdi))->toBeTrue()
        ->and((new ExternalCfdiPolicy)->download($withoutMfa, $cfdi))->toBeFalse()
        ->and((new ExportPolicy)->view($withoutMfa, $export))->toBeFalse()
        ->and((new ImportPolicy)->view($withoutMfa, $import))->toBeFalse();

    $export->forceFill(['user_id' => $withMfa->id]);
    expect((new ExportPolicy)->view($withMfa, $export))->toBeTrue();
});

it('en producción no arranca con APP_DEBUG y fuerza la cookie segura', function (): void {
    expect(ProductionSafety::problems(true, true))->toBe([ProductionSafety::DEBUG_ENABLED])
        ->and(ProductionSafety::problems(true, false))->toBe([])
        ->and(ProductionSafety::problems(false, true))->toBe([]);

    config(['app.debug' => true]);
    expect(fn () => ProductionSafety::enforce(true))->toThrow(RuntimeException::class, 'APP_DEBUG=true');

    config(['app.debug' => false, 'session.secure' => false]);
    ProductionSafety::enforce(true);
    expect(config('session.secure'))->toBeTrue();
});

it('el SMTP no acepta destinos del propio CRM, del equipo local ni de la metadata de la nube', function (?string $host, ?int $port, bool $blocked): void {
    expect(UpdateMailSettings::blockedDestination($host, $port) !== null)->toBe($blocked);
})->with([
    'localhost' => ['localhost', 587, true],
    'loopback' => ['127.0.0.1', 25, true],
    'loopback IPv6' => ['[::1]', 25, true],
    'metadata de la nube' => ['169.254.169.254', 80, true],
    'servicio redis' => ['redis', 25, true],
    'servicio postgres' => ['postgres', 25, true],
    'puerto de Redis' => ['smtp.ejemplo.test', 6379, true],
    'puerto de PostgreSQL' => ['smtp.ejemplo.test', 5432, true],
    'relay corporativo' => ['10.0.0.25', 25, false],
    'proveedor externo' => ['smtp.ejemplo.test', 587, false],
    'sin servidor' => [null, null, false],
]);
