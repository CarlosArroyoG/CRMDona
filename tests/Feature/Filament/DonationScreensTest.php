<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\Role;
use App\Filament\Resources\Donations\Pages\CreateDonation;
use App\Filament\Resources\Donations\Pages\ListDonations;
use App\Filament\Resources\Donations\Pages\ViewDonation;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Program;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('registra un donativo desde el formulario como "Por confirmar"', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));
    $donor = Donor::factory()->create();
    $campaign = Campaign::factory()->create();

    Livewire::test(CreateDonation::class)
        ->fillForm([
            'donor_id' => $donor->id,
            'kind' => 'monetary',
            'manual_payment_method' => 'bank_transfer',
            'amount' => '2,500.75',
            'received_on' => now()->toDateString(),
            'destination' => 'campaign',
            'campaign_id' => $campaign->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $donation = Donation::query()->sole();
    expect($donation->status)->toBe(DonationStatus::Pending)
        ->and($donation->amount)->toBe('2500.75')
        ->and($donation->campaign_id)->toBe($campaign->id)
        ->and($donation->program_id)->toBeNull();
});

it('muestra el error de importe en el campo del formulario', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(CreateDonation::class)
        ->fillForm([
            'donor_id' => Donor::factory()->create()->id,
            'kind' => 'monetary',
            'manual_payment_method' => 'cash',
            'amount' => '10.555',
            'received_on' => now()->toDateString(),
            'destination' => 'general',
        ])
        ->call('create')
        ->assertHasFormErrors(['amount']);

    expect(Donation::query()->count())->toBe(0);
});

it('confirma y cancela según el rol', function (): void {
    $donation = Donation::factory()->create();

    actingAs(userWithRole(Role::FundraisingCoordinator));
    Livewire::test(ViewDonation::class, ['record' => $donation->id])
        ->assertActionHidden('confirm')
        ->assertActionHidden('cancel');

    actingAs(userWithRole(Role::Accountant));
    Livewire::test(ViewDonation::class, ['record' => $donation->id])->callAction('confirm');
    expect($donation->fresh()?->status)->toBe(DonationStatus::Confirmed);

    Livewire::test(ViewDonation::class, ['record' => $donation->id])
        ->assertActionHidden('edit')
        ->callAction('cancel', ['cancellation_reason' => 'Se capturó con importe equivocado'])
        ->assertHasNoActionErrors();
    expect($donation->fresh()?->status)->toBe(DonationStatus::Cancelled);
});

it('exige motivo para cancelar', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $donation = Donation::factory()->create();

    Livewire::test(ViewDonation::class, ['record' => $donation->id])
        ->callAction('cancel', ['cancellation_reason' => ''])
        ->assertHasActionErrors(['cancellation_reason' => 'required']);

    expect($donation->fresh()?->status)->toBe(DonationStatus::Pending);
});

it('filtra donativos por estado, programa, fechas e importe', function (): void {
    actingAs(userWithRole(Role::ReadOnly));
    $becas = Program::factory()->create();
    $viaCampaign = Donation::factory()->create(['campaign_id' => Campaign::factory()->create(['program_id' => $becas->id])->id, 'amount' => '5000.00', 'received_on' => '2026-03-10']);
    $direct = Donation::factory()->confirmed()->create(['program_id' => $becas->id, 'amount' => '100.00', 'received_on' => '2026-05-01']);
    $other = Donation::factory()->create(['amount' => '700.00', 'received_on' => '2025-12-24']);

    Livewire::test(ListDonations::class)
        ->filterTable('program', $becas->id)
        ->assertCanSeeTableRecords([$viaCampaign, $direct])
        ->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('status', DonationStatus::Confirmed->value)
        ->assertCanSeeTableRecords([$direct])
        ->assertCanNotSeeTableRecords([$viaCampaign, $other])
        ->resetTableFilters()
        ->filterTable('received_on', ['from' => '2026-01-01', 'until' => '2026-04-30'])
        ->assertCanSeeTableRecords([$viaCampaign])
        ->assertCanNotSeeTableRecords([$direct, $other])
        ->resetTableFilters()
        ->filterTable('amount', ['min' => '500', 'max' => '1,000'])
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$viaCampaign, $direct]);
});

it('busca donativos por donante sin acentos', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $donation = Donation::factory()->create(['donor_id' => Donor::factory()->create(['first_name' => 'Ángela', 'last_name' => 'Ibáñez'])->id]);
    $other = Donation::factory()->create();

    Livewire::test(ListDonations::class)
        ->searchTable('angela ibanez')
        ->assertCanSeeTableRecords([$donation])
        ->assertCanNotSeeTableRecords([$other]);
});
