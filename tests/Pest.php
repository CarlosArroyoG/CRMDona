<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Usuario activo con el rol indicado (correo ficticio de example.com).
 */
function userWithRole(Role $role): User
{
    return User::factory()->withRole($role)->create();
}
