<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DonationKind;
use App\Enums\DonationOrigin;
use App\Enums\DonationStatus;
use App\Enums\ManualPaymentMethod;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'donor_id' => Donor::factory(),
            'origin' => DonationOrigin::Manual,
            'kind' => DonationKind::Monetary,
            'manual_payment_method' => fake()->randomElement(ManualPaymentMethod::cases()),
            'amount' => fake()->randomElement(['100.00', '250.00', '500.00', '1000.00', '1500.50', '5000.00']),
            'currency' => 'MXN',
            'received_on' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'status' => DonationStatus::Pending,
            'tax_receipt_requested' => false,
            'registered_by_id' => User::factory(),
        ];
    }

    public function inKind(): static
    {
        return $this->state(fn (): array => [
            'kind' => DonationKind::InKind,
            'manual_payment_method' => null,
            'in_kind_description' => fake()->randomElement(['Despensas para comedor', 'Útiles escolares', 'Equipo de cómputo usado']),
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DonationStatus::Confirmed,
            'confirmed_at' => now(),
            'confirmed_by_id' => $attributes['registered_by_id'],
        ]);
    }
}
