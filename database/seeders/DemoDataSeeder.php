<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\DonationStatus;
use App\Enums\ProgramStatus;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\Program;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Datos de demostración ficticios (solo local y testing). Los registra un
 * usuario técnico sin rol, desactivado y con contraseña aleatoria que nadie
 * conoce: no existe ninguna credencial utilizable.
 */
class DemoDataSeeder extends Seeder
{
    public const string DEMO_USER_EMAIL = 'datos-demostracion@example.com';

    public function run(): void
    {
        $registrar = new User([
            'name' => 'Datos de demostración',
            'email' => self::DEMO_USER_EMAIL,
            'password' => Str::random(64),
        ]);
        $registrar->forceFill(['role' => null, 'deactivated_at' => now()])->save();

        $programs = collect(['Becas', 'Formación', 'Alimentación', 'Infraestructura'])
            ->mapWithKeys(fn (string $name): array => [$name => Program::query()->create([
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => "Programa de {$name} (dato de demostración).",
                'status' => ProgramStatus::Active,
            ])])->all();

        $campaigns = collect([
            ['Navidad 2026', 'Alimentación', '2026-11-15', '2026-12-31', CampaignStatus::Draft],
            ['Regreso a clases 2027', 'Becas', '2027-06-01', '2027-08-31', CampaignStatus::Draft],
            ['Campaña anual de becas', 'Becas', '2026-01-15', '2026-12-15', CampaignStatus::Active],
        ])->map(fn (array $row): Campaign => Campaign::query()->create([
            'program_id' => $programs[$row[1]]->id,
            'name' => $row[0],
            'slug' => Str::slug($row[0]),
            'description' => 'Campaña de demostración.',
            'status' => $row[4],
            'starts_on' => $row[2],
            'ends_on' => $row[3],
            'goal_amount' => '250000.00',
        ]));

        $tags = collect(['Padrino', 'Empresa', 'Recurrente', 'Evento 2026'])
            ->map(fn (string $name): Tag => Tag::query()->create(['name' => $name]));

        $donors = Donor::factory()->count(8)->create(['registered_by_id' => $registrar->id])
            ->merge(Donor::factory()->count(2)->withTaxProfile()->create(['registered_by_id' => $registrar->id]))
            ->merge(Donor::factory()->organization()->count(3)->withTaxProfile()->create(['registered_by_id' => $registrar->id]));

        $donors->each(fn (Donor $donor) => $donor->tags()->attach($tags->random(random_int(0, 2))->pluck('id')));

        foreach ($donors as $index => $donor) {
            Donation::factory()->count(2)->create([
                'donor_id' => $donor->id,
                'registered_by_id' => $registrar->id,
                'campaign_id' => $index % 2 === 0 ? $campaigns->random()->id : null,
                // Destino único: campaña, programa o fondo general.
                'program_id' => $index % 2 !== 0 && $index % 3 === 1 ? collect($programs)->random()->id : null,
            ]);
        }

        Donation::factory()->count(5)->confirmed()->create([
            'donor_id' => $donors->random()->id,
            'registered_by_id' => $registrar->id,
            'confirmed_by_id' => $registrar->id,
        ]);
        Donation::factory()->inKind()->create([
            'donor_id' => $donors->random()->id,
            'registered_by_id' => $registrar->id,
            'program_id' => $programs['Alimentación']->id,
        ]);
        Donation::factory()->create([
            'donor_id' => $donors->random()->id,
            'registered_by_id' => $registrar->id,
            'status' => DonationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by_id' => $registrar->id,
            'cancellation_reason' => 'Importe capturado con error (dato de demostración).',
        ]);
    }
}
