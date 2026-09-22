<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

use function Pest\Laravel\seed;

it('no crea usuarios con credenciales conocidas', function (): void {
    seed(DatabaseSeeder::class);

    expect(User::query()->count())->toBe(0);
});
