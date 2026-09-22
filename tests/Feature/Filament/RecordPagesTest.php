<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Resources\Donations\Pages\EditDonation;
use App\Filament\Resources\Donors\Pages\EditDonor;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Program;
use App\Models\Tag;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('abre la ficha y la edición de cada tipo de registro', function (): void {
    $admin = userWithRole(Role::Administrator);
    actingAs($admin);
    $donor = Donor::factory()->withTaxProfile()->create();
    $program = Program::factory()->create();
    $campaign = Campaign::factory()->create(['program_id' => $program->id]);
    $donation = Donation::factory()->create(['donor_id' => $donor->id, 'campaign_id' => $campaign->id]);
    $log = AuditLog::query()->firstOrFail();

    foreach ([
        "/admin/donors/{$donor->id}", "/admin/donors/{$donor->id}/edit",
        "/admin/programs/{$program->id}", "/admin/programs/{$program->id}/edit",
        "/admin/campaigns/{$campaign->id}", "/admin/campaigns/{$campaign->id}/edit",
        "/admin/donations/{$donation->id}", "/admin/donations/{$donation->id}/edit",
        "/admin/users/{$admin->id}/edit", "/admin/audit-logs/{$log->id}",
    ] as $url) {
        actingAs($admin)->get($url)->assertOk();
    }
});

it('precarga etiquetas, consentimiento y datos fiscales al editar un donante', function (): void {
    actingAs(userWithRole(Role::Administrator));
    $donor = Donor::factory()->withTaxProfile()->create();
    $tag = Tag::query()->create(['name' => 'Padrino']);
    $donor->tags()->attach($tag);

    Livewire::test(EditDonor::class, ['record' => $donor->id])
        ->assertFormSet([
            'tag_ids' => [$tag->id],
            'has_tax_profile' => true,
            'tax_profile.rfc' => $donor->taxProfile()->value('rfc'),
        ]);
});

it('precarga el destino al editar un donativo pendiente', function (): void {
    actingAs(userWithRole(Role::FundraisingCoordinator));
    $donation = Donation::factory()->create(['program_id' => Program::factory()->create()->id]);

    Livewire::test(EditDonation::class, ['record' => $donation->id])
        ->assertFormSet(['destination' => 'program', 'program_id' => $donation->program_id]);
});

it('no abre la edición de un donativo confirmado', function (): void {
    $admin = userWithRole(Role::Administrator);
    $donation = Donation::factory()->confirmed()->create();

    actingAs($admin)->get("/admin/donations/{$donation->id}/edit")->assertForbidden();
});
