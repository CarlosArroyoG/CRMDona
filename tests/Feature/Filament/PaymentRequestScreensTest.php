<?php

declare(strict_types=1);

use App\Actions\PaymentRequests\CreatePaymentRequest;
use App\Enums\PaymentRequestFrequency;
use App\Enums\PaymentRequestStatus;
use App\Enums\Role;
use App\Enums\TaxRegime;
use App\Filament\Resources\Donations\Pages\CreateDonation;
use App\Filament\Resources\Donations\Pages\EditDonation;
use App\Filament\Resources\PaymentRequests\Pages\ListPaymentRequests;
use App\Filament\Resources\PaymentRequests\Pages\ViewPaymentRequest;
use App\Filament\Resources\PaymentRequests\PaymentRequestResource;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\OrganizationSetting;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Program;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Mail::fake();
    OrganizationSetting::current()->forceFill([
        'legal_name' => 'FUNDACION DE PRUEBA', 'tax_regime' => TaxRegime::NonProfitLegalEntities,
        'privacy_notice_url' => 'https://www.fdonbosco.org/aviso-de-privacidad', 'privacy_notice_version' => '2026-09',
    ])->save();
});

it('"Ya se recibió" conserva exactamente el registro manual', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));

    Livewire::test(CreateDonation::class)
        ->assertFormFieldIsVisible('collection')
        ->fillForm([
            'collection' => 'received', 'donor_id' => Donor::factory()->create()->id, 'kind' => 'monetary',
            'manual_payment_method' => 'cash', 'amount' => '100', 'received_on' => now()->toDateString(), 'destination' => 'general',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Donation::query()->count())->toBe(1)->and(PaymentRequest::query()->count())->toBe(0);
});

it('"Cobrar con tarjeta en línea" oculta lo manual, NO crea donativo y lleva a la ficha con sus acciones', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));
    $donor = Donor::factory()->create(['email' => 'ana@example.com']);
    $program = Program::factory()->create();

    $component = Livewire::test(CreateDonation::class)
        ->fillForm(['collection' => 'card'])
        ->assertFormFieldIsHidden('kind')->assertFormFieldIsHidden('manual_payment_method')
        ->assertFormFieldIsHidden('received_on')->assertFormFieldIsHidden('reference')->assertFormFieldIsHidden('notes')
        ->assertFormFieldIsVisible('frequency')
        ->fillForm([
            'collection' => 'card', 'donor_id' => $donor->id, 'frequency' => 'monthly', 'amount' => '350',
            'destination' => 'program', 'program_id' => $program->id, 'tax_receipt_requested' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $request = PaymentRequest::query()->sole();
    $component->assertRedirect(PaymentRequestResource::getUrl('view', ['record' => $request]));
    expect(Donation::query()->count())->toBe(0)->and(Payment::query()->count())->toBe(0)
        ->and($request->frequency)->toBe(PaymentRequestFrequency::Monthly)->and($request->amount)->toBe('350.00')
        ->and($request->program_id)->toBe($program->id)->and($request->tax_receipt_requested)->toBeTrue();

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->id])
        ->assertActionVisible('openPayment')->assertActionVisible('copyLink')->assertActionVisible('sendEmail')
        ->assertActionVisible('regenerate')->assertActionVisible('cancel')
        ->assertSee($request->url())->assertSee('Esperando pago');
});

it('los errores de la solicitud se muestran en el formulario (importe inválido)', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(CreateDonation::class)
        ->fillForm(['collection' => 'card', 'donor_id' => Donor::factory()->create()->id, 'frequency' => 'one_time', 'amount' => '10.555', 'destination' => 'general'])
        ->call('create')
        ->assertHasFormErrors(['amount']);

    expect(PaymentRequest::query()->count())->toBe(0);
});

it('el Contador registra donativos manuales pero no ve la opción de tarjeta; al editar no aparece el selector', function (): void {
    actingAs(userWithRole(Role::Accountant));
    Livewire::test(CreateDonation::class)->assertFormFieldIsHidden('collection');

    actingAs(userWithRole(Role::Administrator));
    $donation = Donation::factory()->create();
    Livewire::test(EditDonation::class, ['record' => $donation->id])->assertFormFieldIsHidden('collection');
});

it('RBAC en la ficha: Contador y Solo lectura ven la solicitud, pero no el enlace ni las acciones', function (Role $role): void {
    $request = app(CreatePaymentRequest::class)->handle(['donor_id' => Donor::factory()->create()->id, 'amount' => '500', 'frequency' => 'one_time'], userWithRole(Role::Administrator));
    actingAs(userWithRole($role));

    Livewire::test(ListPaymentRequests::class)->assertCanSeeTableRecords([$request]);
    Livewire::test(ViewPaymentRequest::class, ['record' => $request->id])
        ->assertActionHidden('openPayment')->assertActionHidden('copyLink')->assertActionHidden('sendEmail')
        ->assertActionHidden('regenerate')->assertActionHidden('cancel')
        ->assertDontSee($request->token);
})->with([
    'Contador' => [Role::Accountant],
    'Solo lectura' => [Role::ReadOnly],
]);

it('cancelar y regenerar desde la ficha; una cancelada ya no ofrece acciones', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));
    $request = app(CreatePaymentRequest::class)->handle(['donor_id' => Donor::factory()->create()->id, 'amount' => '500', 'frequency' => 'one_time'], userWithRole(Role::Administrator));
    $oldToken = $request->token;

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->id])->callAction('regenerate');
    expect($request->refresh()->token)->not->toBe($oldToken);

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->id])->callAction('sendEmail');
    Mail::assertSentCount(1);

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->id])->callAction('cancel');
    expect($request->refresh()->status)->toBe(PaymentRequestStatus::Cancelled);

    Livewire::test(ViewPaymentRequest::class, ['record' => $request->id])
        ->assertActionHidden('openPayment')->assertActionHidden('cancel')->assertActionHidden('regenerate')
        ->assertSee('Cancelada');
});

it('la ruta de solicitudes respeta permisos: todos los roles con payments.view la abren', function (Role $role): void {
    actingAs(userWithRole($role))->get('/admin/solicitudes-de-pago')->assertOk();
})->with([Role::Administrator, Role::FundraisingCoordinator, Role::Accountant, Role::ReadOnly]);
