<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DonorType;
use App\Enums\TaxRegime;
use App\Models\Donor;
use App\Models\User;
use Faker\Generator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Solo datos ficticios: correos en dominios example.*, sin teléfonos y RFC
 * con prefijo "ZZ" (estructura válida, sin corresponder a nombres reales).
 * Faker no incluye es_MX; los nombres usan es_ES para que suenen en español.
 *
 * @extends Factory<Donor>
 */
class DonorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => DonorType::Individual,
            'first_name' => $this->spanish()->firstName(),
            'last_name' => $this->spanish()->lastName(),
            'second_last_name' => $this->spanish()->optional()->lastName(),
            'birth_date' => fake()->optional()->dateTimeBetween('-80 years', '-18 years'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'accepts_communications' => false,
            'communications_consent_updated_at' => now(),
            'registered_by_id' => User::factory(),
        ];
    }

    public function organization(): static
    {
        return $this->state(fn (): array => [
            'type' => DonorType::Organization,
            'first_name' => null,
            'last_name' => null,
            'second_last_name' => null,
            'birth_date' => null,
            'legal_name' => $this->spanish()->company().' S.A. de C.V.',
            'contact_name' => $this->spanish()->name(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }

    public function withTaxProfile(): static
    {
        return $this->afterCreating(function (Donor $donor): void {
            $donor->refresh();
            $individual = $donor->type === DonorType::Individual;
            $donor->taxProfile()->create([
                'rfc' => mb_strtoupper('ZZ'.fake()->lexify($individual ? '??' : '?').fake()->date('ymd').fake()->bothify('??#')),
                'tax_name' => mb_strtoupper($donor->display_name),
                'tax_regime' => $individual ? TaxRegime::Wages : TaxRegime::GeneralLegalEntities,
                'tax_postal_code' => fake()->numerify('#####'),
                'cfdi_use' => null,
            ]);
        });
    }

    private function spanish(): Generator
    {
        return fake('es_ES');
    }
}
