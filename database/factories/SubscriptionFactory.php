<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentProvider;
use App\Enums\RetryOwner;
use App\Enums\SubscriptionInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Donor;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => PaymentProvider::Fake,
            'external_id' => 'fake_sub_'.Str::lower(Str::random(14)),
            'donor_id' => Donor::factory(),
            'amount' => fake()->randomElement(['150.00', '300.00', '500.00']),
            'currency' => 'MXN',
            'interval' => SubscriptionInterval::Monthly,
            'status' => SubscriptionStatus::Active,
            'retry_owner' => RetryOwner::Provider,
            'started_at' => now(),
            'idempotency_key' => 'factory-'.Str::uuid()->toString(),
        ];
    }
}
