<?php

declare(strict_types=1);

use App\Actions\Donors\SaveDonor;
use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\DonorTaxProfile;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentDispute;
use App\Models\PaymentIncident;
use App\Models\PaymentIncidentNote;
use App\Models\Program;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

it('registra quién, qué y cuándo al crear y modificar', function (): void {
    $actor = userWithRole(Role::FundraisingCoordinator);
    actingAs($actor);
    $program = Program::factory()->create(['name' => 'Becas']);

    $program->update(['name' => 'Becas escolares']);

    $log = AuditLog::query()->where('auditable_type', 'program')->where('event', AuditEvent::Updated->value)->firstOrFail();
    expect($log->user_id)->toBe($actor->id)
        ->and($log->auditable_id)->toBe($program->id)
        ->and($log->changed_fields)->toBe(['name'])
        ->and($log->old_values)->toBe(['name' => 'Becas'])
        ->and($log->new_values)->toBe(['name' => 'Becas escolares'])
        ->and($log->created_at)->not->toBeNull();
});

it('no copia datos personales del donante a la bitácora', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $donor = app(SaveDonor::class)->handle(null, [
        'type' => 'individual', 'first_name' => 'Rosa', 'last_name' => 'Secreta', 'email' => 'rosa.secreta@example.com',
        'phone' => '55 5555 5555', 'birth_date' => '1980-05-05', 'notes' => 'Nota privada',
    ], userWithRole(Role::Administrator));
    $donor->update(['email' => 'nuevo.correo@example.com', 'last_name' => 'Cambiada']);

    $serialized = AuditLog::query()->where('auditable_type', 'donor')->get()
        ->map(fn (AuditLog $log): string => json_encode([$log->old_values, $log->new_values], JSON_THROW_ON_ERROR))
        ->implode(' ');

    foreach (['Rosa', 'Secreta', 'rosa.secreta', 'nuevo.correo', '5555', '1980-05-05', 'Nota privada'] as $private) {
        expect($serialized)->not->toContain($private);
    }

    $update = AuditLog::query()->where('auditable_type', 'donor')->where('event', AuditEvent::Updated->value)->firstOrFail();
    expect($update->changed_fields)->toEqualCanonicalizing(['email', 'last_name'])
        ->and($update->old_values)->toBeNull();
});

it('no copia RFC, nombre fiscal ni código postal a la bitácora', function (): void {
    $donor = Donor::factory()->withTaxProfile()->create();
    $profile = DonorTaxProfile::query()->where('donor_id', $donor->id)->firstOrFail();

    $log = AuditLog::query()->where('auditable_type', 'donor_tax_profile')->firstOrFail();
    $serialized = json_encode($log->new_values, JSON_THROW_ON_ERROR);

    expect($log->changed_fields)->toContain('rfc')
        ->and($serialized)->not->toContain($profile->rfc)
        ->and($serialized)->not->toContain($profile->tax_postal_code)
        ->and($log->new_values)->toHaveKey('tax_regime');
});

it('nunca registra contraseñas ni tokens', function (): void {
    $user = User::factory()->create();
    $user->update(['password' => 'otra-clave-2026']);

    $logs = AuditLog::query()->where('auditable_type', 'user')->get();
    $serialized = $logs->map(fn (AuditLog $log): string => json_encode([$log->old_values, $log->new_values], JSON_THROW_ON_ERROR))->implode(' ');

    expect($logs->last()?->changed_fields)->toBe(['password'])
        ->and($serialized)->not->toContain('otra-clave')
        ->and($serialized)->not->toContain('$2y$')
        ->and($serialized)->not->toContain('remember_token');
});

it('no registra cambios de campos no auditados', function (): void {
    $donor = Donor::factory()->create();
    $before = AuditLog::query()->count();

    $donor->touch();

    expect(AuditLog::query()->count())->toBe($before);
});

it('usa el evento de negocio en lugar de "modificación"', function (): void {
    $donation = Donation::factory()->create();
    $before = AuditLog::query()->where('auditable_type', 'donation')->count();

    $donation->auditAs(AuditEvent::Confirmed)->forceFill([
        'status' => 'confirmed', 'confirmed_at' => now(), 'confirmed_by_id' => $donation->registered_by_id,
    ])->save();

    $logs = AuditLog::query()->where('auditable_type', 'donation')->get();
    expect($logs->count())->toBe($before + 1)
        ->and($logs->last()?->event)->toBe(AuditEvent::Confirmed)
        ->and($logs->last()?->new_values)->toMatchArray(['status' => 'confirmed']);
});

it('impide modificar o eliminar la bitácora desde la base de datos', function (): void {
    Program::factory()->create();
    $log = AuditLog::query()->firstOrFail();

    expect(fn () => DB::transaction(fn () => AuditLog::query()->whereKey($log->id)->update(['event' => 'deleted'])))
        ->toThrow(QueryException::class, 'La bitácora no se modifica')
        ->and(fn () => DB::transaction(fn () => AuditLog::query()->whereKey($log->id)->delete()))
        ->toThrow(QueryException::class, 'La bitácora no se modifica');
});

it('tiene etiqueta en español para cada tipo y campo auditado', function (): void {
    $models = [
        'user' => User::class, 'donor' => Donor::class, 'donor_tax_profile' => DonorTaxProfile::class,
        'tag' => Tag::class, 'program' => Program::class, 'campaign' => Campaign::class,
        'donation' => Donation::class, 'organization_setting' => OrganizationSetting::class,
        'payment' => Payment::class, 'payment_attempt' => PaymentAttempt::class, 'subscription' => Subscription::class,
        'refund' => Refund::class, 'payment_dispute' => PaymentDispute::class, 'payment_incident' => PaymentIncident::class,
        'payment_incident_note' => PaymentIncidentNote::class,
    ];

    foreach ($models as $type => $class) {
        expect(trans("audit.types.{$type}"))->not->toBe("audit.types.{$type}");

        foreach ([...$class::auditValueFields(), ...$class::auditNameOnlyFields()] as $field) {
            expect(trans("audit.fields.{$field}"))->not->toBe("audit.fields.{$field}", "Falta la etiqueta de {$field}");
        }
    }

    expect(trans('audit.fields.tags'))->not->toBe('audit.fields.tags');
});
