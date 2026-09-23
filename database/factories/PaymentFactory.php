<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentKind;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Donor;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Pagos ficticios con la pasarela simulada. Para flujos completos, las
 * pruebas usan las Actions con FakeGateway; esta fábrica es para datos de
 * apoyo (pantallas, permisos, restricciones).
 *
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => PaymentProvider::Fake,
            'external_id' => 'fake_pay_'.Str::lower(Str::random(14)),
            'kind' => PaymentKind::OneTime,
            'donor_id' => Donor::factory(),
            'amount' => fake()->randomElement(['100.00', '250.00', '500.00', '1000.00']),
            'currency' => 'MXN',
            'status' => PaymentStatus::Pending,
            'idempotency_key' => 'factory-'.Str::uuid()->toString(),
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Succeeded,
            'provider_status' => 'succeeded',
            'succeeded_at' => now(),
        ]);
    }
}
