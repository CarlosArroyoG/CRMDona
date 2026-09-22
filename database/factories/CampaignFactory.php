<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Campaña '.implode(' ', (array) fake()->unique()->words(2));
        $start = fake()->dateTimeBetween('-6 months', '+1 month');

        return [
            'program_id' => null,
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'status' => CampaignStatus::Active,
            'starts_on' => $start,
            'ends_on' => (clone $start)->modify('+2 months'),
            'goal_amount' => '100000.00',
        ];
    }
}
