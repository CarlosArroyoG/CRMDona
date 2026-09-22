<?php

declare(strict_types=1);

use App\Actions\Donors\DeleteDonor;
use App\Actions\Donors\FindDonorDuplicates;
use App\Actions\Donors\SaveDonor;
use App\Actions\Donors\SaveDonorTaxProfile;
use App\Actions\Donors\SetDonorArchived;
use App\Enums\AuditEvent;
use App\Enums\DonorType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function individualInput(array $overrides = []): array
{
    return [
        'type' => 'individual',
        'first_name' => 'José',
        'last_name' => 'Peña',
        'second_last_name' => 'Ruiz',
        'email' => 'Jose.Pena@Example.com',
        'phone' => '55 1234 5678',
        'accepts_communications' => true,
        ...$overrides,
    ];
}

/**
 * @return array<string, array<int, string>>
 */
function donorErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Se esperaba un error de validación.');
}

it('registra una persona física y calcula su nombre completo', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);

    $donor = app(SaveDonor::class)->handle(null, individualInput(), $actor);

    expect($donor->type)->toBe(DonorType::Individual)
        ->and($donor->display_name)->toBe('José Peña Ruiz')
        ->and($donor->email)->toBe('jose.pena@example.com')
        ->and($donor->registered_by_id)->toBe($actor->id)
        ->and($donor->accepts_communications)->toBeTrue()
        ->and($donor->communications_consent_updated_at)->not->toBeNull();
});

it('registra una persona moral sin campos de persona física', function (): void {
    $donor = app(SaveDonor::class)->handle(null, [
        'type' => 'organization',
        'legal_name' => 'Constructora Ficticia S.A. de C.V.',
        'contact_name' => 'Laura Gómez',
        'first_name' => 'Ignorado',
        'birth_date' => '1990-01-01',
    ], userWithRole(Role::Administrator));

    expect($donor->display_name)->toBe('Constructora Ficticia S.A. de C.V.')
        ->and($donor->first_name)->toBeNull()
        ->and($donor->birth_date)->toBeNull();
});

it('exige los campos según el tipo de persona', function (): void {
    $actor = userWithRole(Role::Administrator);

    expect(donorErrors(fn () => app(SaveDonor::class)->handle(null, ['type' => 'individual'], $actor)))
        ->toHaveKeys(['first_name', 'last_name'])
        ->and(donorErrors(fn () => app(SaveDonor::class)->handle(null, ['type' => 'organization'], $actor)))
        ->toHaveKey('legal_name');
});

it('refuerza las reglas por tipo en la base de datos', function (): void {
    $donor = Donor::factory()->create();

    expect(fn () => $donor->forceFill(['legal_name' => 'Mezcla indebida'])->save())
        ->toThrow(QueryException::class);
});

it('no registra la aceptación del aviso si no hay aviso configurado', function (): void {
    $errors = donorErrors(fn () => app(SaveDonor::class)->handle(null, individualInput(['privacy_notice_accepted' => true]), userWithRole(Role::Administrator)));

    expect($errors['privacy_notice_accepted'][0])->toBe(SaveDonor::PRIVACY_NOTICE_MISSING)
        ->and(Donor::query()->count())->toBe(0);
});

it('registra la versión vigente y la fecha al aceptar el aviso', function (): void {
    OrganizationSetting::current()->fill(['privacy_notice_url' => 'https://example.com/aviso', 'privacy_notice_version' => '2026-09'])->save();

    $donor = app(SaveDonor::class)->handle(null, individualInput(['privacy_notice_accepted' => true, 'accepts_communications' => false]), userWithRole(Role::Administrator));

    expect($donor->privacy_notice_version)->toBe('2026-09')
        ->and($donor->privacy_notice_accepted_at)->not->toBeNull()
        ->and($donor->accepts_communications)->toBeFalse();
});

it('no considera aceptado el aviso sin evidencia', function (): void {
    expect(fn () => Donor::factory()->create(['privacy_notice_version' => '2026-09', 'privacy_notice_accepted_at' => null]))
        ->toThrow(QueryException::class);
});

it('guarda datos fiscales solo si quien guarda tiene permiso', function (Role $role, bool $saved): void {
    $input = individualInput([
        'has_tax_profile' => true,
        'tax_profile' => ['rfc' => 'zzab800101ab1', 'tax_name' => 'JOSE PEÑA RUIZ', 'tax_regime' => '605', 'tax_postal_code' => '62000'],
    ]);

    $donor = app(SaveDonor::class)->handle(null, $input, userWithRole($role));

    expect($donor->taxProfile()->exists())->toBe($saved);
    if ($saved) {
        expect($donor->taxProfile?->rfc)->toBe('ZZAB800101AB1');
    }
})->with([
    'Administrador' => [Role::Administrator, true],
    'Coordinador' => [Role::FundraisingCoordinator, true],
]);

it('valida solo la estructura del RFC según el tipo de persona', function (): void {
    $donor = Donor::factory()->create();

    $errors = donorErrors(fn () => app(SaveDonorTaxProfile::class)->handle($donor, [
        'rfc' => 'ZZA800101AB1', 'tax_name' => 'X', 'tax_regime' => '605', 'tax_postal_code' => '6200',
    ]));

    expect($errors)->toHaveKeys(['rfc', 'tax_postal_code']);
});

it('no borra los datos fiscales si el formulario no los envió', function (): void {
    $donor = Donor::factory()->withTaxProfile()->create();

    app(SaveDonor::class)->handle($donor, individualInput(), userWithRole(Role::Administrator));

    expect($donor->taxProfile()->exists())->toBeTrue();
});

it('registra en la bitácora el cambio de etiquetas con sus nombres', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    $padrino = Tag::query()->create(['name' => 'Padrino']);
    $empresa = Tag::query()->create(['name' => 'Empresa']);
    $donor = app(SaveDonor::class)->handle(null, individualInput(['tag_ids' => [$padrino->id]]), $actor);

    app(SaveDonor::class)->handle($donor, individualInput(['tag_ids' => [$empresa->id, $padrino->id]]), $actor);

    $log = AuditLog::query()->where('event', AuditEvent::TagsChanged->value)->latest('id')->firstOrFail();
    expect($log->old_values)->toBe(['tags' => ['Padrino']])
        ->and($log->new_values)->toBe(['tags' => ['Empresa', 'Padrino']]);
});

it('advierte duplicados por correo o RFC sin impedir el registro', function (): void {
    $existing = Donor::factory()->withTaxProfile()->create(['email' => 'familia@example.com']);
    $rfc = (string) $existing->taxProfile()->value('rfc');

    $finder = app(FindDonorDuplicates::class);
    expect($finder->handle('FAMILIA@example.com', null)->pluck('id')->all())->toBe([$existing->id])
        ->and($finder->handle(null, mb_strtolower($rfc))->pluck('id')->all())->toBe([$existing->id])
        ->and($finder->handle('familia@example.com', null, $existing->id))->toBeEmpty();

    app(SaveDonor::class)->handle(null, individualInput(['email' => 'familia@example.com']), userWithRole(Role::Administrator));
    expect(Donor::query()->where('email', 'familia@example.com')->count())->toBe(2);
});

it('archiva y reactiva con evento propio en la bitácora', function (): void {
    $donor = Donor::factory()->create();

    app(SetDonorArchived::class)->handle($donor, true);
    expect($donor->fresh()?->isArchived())->toBeTrue()
        ->and(AuditLog::query()->where('event', AuditEvent::Archived->value)->exists())->toBeTrue();

    app(SetDonorArchived::class)->handle($donor, false);
    expect($donor->fresh()?->isArchived())->toBeFalse();
});

it('no elimina un donante con donativos, ni por aplicación ni por base de datos', function (): void {
    $donation = Donation::factory()->create();
    $donor = $donation->donor;

    expect(donorErrors(fn () => app(DeleteDonor::class)->handle($donor))['donor'][0])->toBe(DeleteDonor::HAS_DONATIONS)
        ->and(fn () => Donor::query()->whereKey($donor->id)->delete())->toThrow(QueryException::class);
});

it('elimina un donante sin donativos junto con sus datos fiscales', function (): void {
    $donor = Donor::factory()->withTaxProfile()->create();

    app(DeleteDonor::class)->handle($donor);

    expect(Donor::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', AuditEvent::Deleted->value)->where('auditable_type', 'donor')->exists())->toBeTrue();
});
