<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Resources\Donors\Pages\CreateDonor;
use App\Filament\Resources\Donors\Pages\ListDonors;
use App\Filament\Resources\Donors\Pages\ViewDonor;
use App\Models\Donor;
use App\Models\Tag;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('registra un donante desde el formulario', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));

    Livewire::test(CreateDonor::class)
        ->fillForm([
            'type' => 'individual',
            'first_name' => 'María',
            'last_name' => 'Núñez',
            'email' => 'maria.nunez@example.com',
            'accepts_communications' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Donor::query()->where('display_name', 'María Núñez')->exists())->toBeTrue();
});

it('muestra en español los errores del formulario de donante', function (): void {
    actingAs(userWithRole(Role::Administrator));

    Livewire::test(CreateDonor::class)
        ->fillForm(['type' => 'organization', 'legal_name' => ''])
        ->call('create')
        ->assertHasFormErrors(['legal_name' => 'required'])
        ->assertSee('razón social')
        ->assertDontSee('validation.');
});

it('busca donantes sin importar acentos ni mayúsculas', function (): void {
    actingAs(userWithRole(Role::ReadOnly));
    $jose = Donor::factory()->create(['first_name' => 'José', 'last_name' => 'Peña', 'second_last_name' => null]);
    $otro = Donor::factory()->create(['first_name' => 'Ana', 'last_name' => 'Ruiz', 'second_last_name' => null]);

    Livewire::test(ListDonors::class)
        ->searchTable('jose pena')
        ->assertCanSeeTableRecords([$jose])
        ->assertCanNotSeeTableRecords([$otro]);
});

it('filtra por tipo, etiquetas y muestra solo activos por defecto', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $tag = Tag::query()->create(['name' => 'Padrino']);
    $padrino = Donor::factory()->create();
    $padrino->tags()->attach($tag);
    $empresa = Donor::factory()->organization()->create();
    $archivado = Donor::factory()->archived()->create();

    Livewire::test(ListDonors::class)
        ->assertCanSeeTableRecords([$padrino, $empresa])
        ->assertCanNotSeeTableRecords([$archivado])
        ->filterTable('type', 'organization')
        ->assertCanSeeTableRecords([$empresa])
        ->assertCanNotSeeTableRecords([$padrino])
        ->resetTableFilters()
        ->filterTable('tags', [$tag->id])
        ->assertCanSeeTableRecords([$padrino])
        ->assertCanNotSeeTableRecords([$empresa]);
});

it('oculta exportación y RFC a Solo lectura', function (): void {
    actingAs(userWithRole(Role::ReadOnly));
    $donor = Donor::factory()->withTaxProfile()->create();

    Livewire::test(ListDonors::class)
        ->assertActionHidden(TestAction::make('export')->table())
        ->assertTableColumnHidden('taxProfile.rfc');

    Livewire::test(ViewDonor::class, ['record' => $donor->id])
        ->assertDontSee((string) $donor->taxProfile()->value('rfc'));
});

it('permite al Contador editar solo los datos fiscales', function (): void {
    actingAs(userWithRole(Role::Accountant));
    $donor = Donor::factory()->create();

    Livewire::test(ViewDonor::class, ['record' => $donor->id])
        ->assertActionHidden('edit')
        ->assertActionHidden('archive')
        ->callAction('taxProfile', [
            'has_tax_profile' => true,
            'rfc' => 'ZZAB800101AB1',
            'tax_name' => 'NOMBRE FICTICIO',
            'tax_regime' => '605',
            'tax_postal_code' => '62000',
        ])
        ->assertHasNoActionErrors();

    expect($donor->taxProfile()->value('rfc'))->toBe('ZZAB800101AB1');
});

it('archiva desde la ficha del donante', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));
    $donor = Donor::factory()->create();

    Livewire::test(ViewDonor::class, ['record' => $donor->id])->callAction('archive');

    expect($donor->fresh()?->isArchived())->toBeTrue();
});
