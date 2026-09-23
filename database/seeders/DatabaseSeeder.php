<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Nunca crea usuarios con credenciales conocidas: el administrador se crea
 * con `php artisan app:create-admin`. Los datos de demostración (ficticios)
 * solo se cargan en local y testing.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call([DemoDataSeeder::class, DemoPaymentsSeeder::class]);
        }
    }
}
