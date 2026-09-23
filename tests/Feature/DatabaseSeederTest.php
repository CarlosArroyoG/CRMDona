<?php

declare(strict_types=1);

use App\Models\Donation;
use App\Models\Donor;
use App\Models\Payment;
use App\Models\PaymentIncident;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

it('no crea usuarios con credenciales utilizables', function (): void {
    seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->whereNotNull('role')->count())->toBe(0)
        ->and(User::query()->whereNull('deactivated_at')->count())->toBe(0)
        ->and(User::query()->value('email'))->toBe(DemoDataSeeder::DEMO_USER_EMAIL);
});

it('carga solo datos ficticios de demostración', function (): void {
    seed(DatabaseSeeder::class);

    $emails = Donor::query()->whereNotNull('email')->pluck('email');

    expect(Donor::query()->count())->toBeGreaterThan(0)
        ->and(Donation::query()->count())->toBeGreaterThan(0)
        ->and(Donor::query()->whereNotNull('phone')->count())->toBe(0)
        ->and($emails->every(fn (string $email): bool => (bool) preg_match('/@example\.(com|net|org)$/', $email)))->toBeTrue()
        ->and(Donor::query()->where('email', 'like', '%licarroyogarfias%')->exists())->toBeFalse();
});

it('carga pagos de demostración solo con la pasarela simulada', function (): void {
    seed(DatabaseSeeder::class);

    expect(Payment::query()->count())->toBeGreaterThan(0)
        ->and(Payment::query()->where('provider', '!=', 'fake')->exists())->toBeFalse()
        ->and(Subscription::query()->where('provider', '!=', 'fake')->exists())->toBeFalse()
        ->and(Donation::query()->where('origin', 'online')->count())->toBe(2)
        ->and(PaymentIncident::query()->count())->toBe(2);
});

it('no carga datos de demostración en producción', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $command = artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
    assert($command instanceof PendingCommand);
    $command->assertSuccessful();

    expect(User::query()->count())->toBe(0)
        ->and(Donor::query()->count())->toBe(0);
});
