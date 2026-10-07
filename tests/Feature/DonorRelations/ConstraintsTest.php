<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Donor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * CHECK de PostgreSQL que deben fallar: se prueban dentro de DB::transaction()
 * (savepoint), como el resto del repositorio, para no abortar la transacción
 * de la prueba.
 */

it('donor_activities exige completed_at y completed_by_id cuando está completada', function (): void {
    $donor = Donor::factory()->create();
    $user = userWithRole(Role::FundraisingCoordinator);
    $row = [
        'donor_id' => $donor->id, 'assigned_to_id' => $user->id, 'created_by_id' => $user->id,
        'type' => 'call', 'subject' => 'x', 'status' => 'completed', 'completed_at' => null, 'completed_by_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('donor_activities')->insert($row)))
        ->toThrow(QueryException::class, 'donor_activities_completed_evidence');
});

it('donor_activities exige cancelled_at y cancelled_by_id cuando está cancelada', function (): void {
    $donor = Donor::factory()->create();
    $user = userWithRole(Role::FundraisingCoordinator);
    $row = [
        'donor_id' => $donor->id, 'assigned_to_id' => $user->id, 'created_by_id' => $user->id,
        'type' => 'call', 'subject' => 'x', 'status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('donor_activities')->insert($row)))
        ->toThrow(QueryException::class, 'donor_activities_cancelled_evidence');
});

it('donor_activities rechaza un tipo o estado fuera del catálogo', function (): void {
    $donor = Donor::factory()->create();
    $user = userWithRole(Role::FundraisingCoordinator);
    $row = [
        'donor_id' => $donor->id, 'assigned_to_id' => $user->id, 'created_by_id' => $user->id,
        'type' => 'carta', 'subject' => 'x', 'status' => 'scheduled', 'scheduled_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('donor_activities')->insert($row)))
        ->toThrow(QueryException::class, 'donor_activities_type_valid');
});

it('tasks exige completed_at y completed_by_id cuando está hecha', function (): void {
    $user = userWithRole(Role::FundraisingCoordinator);
    $row = [
        'assigned_to_id' => $user->id, 'created_by_id' => $user->id, 'title' => 'x',
        'priority' => 'medium', 'status' => 'done', 'completed_at' => null, 'completed_by_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('tasks')->insert($row)))
        ->toThrow(QueryException::class, 'tasks_done_evidence');
});

it('tasks exige cancelled_at y cancelled_by_id cuando está cancelada', function (): void {
    $user = userWithRole(Role::FundraisingCoordinator);
    $row = [
        'assigned_to_id' => $user->id, 'created_by_id' => $user->id, 'title' => 'x',
        'priority' => 'medium', 'status' => 'cancelled', 'cancelled_at' => null, 'cancelled_by_id' => $user->id,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('tasks')->insert($row)))
        ->toThrow(QueryException::class, 'tasks_cancelled_evidence');
});

it('donor_assignments exige que el fin no sea anterior al inicio', function (): void {
    $donor = Donor::factory()->create();
    $user = userWithRole(Role::FundraisingCoordinator);
    $row = [
        'donor_id' => $donor->id, 'user_id' => $user->id, 'assigned_by_id' => $user->id,
        'started_at' => now(), 'ended_at' => now()->subDay(),
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('donor_assignments')->insert($row)))
        ->toThrow(QueryException::class, 'donor_assignments_ended_after_started');
});
