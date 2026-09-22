<?php

declare(strict_types=1);

use App\Actions\Campaigns\DeleteCampaign;
use App\Actions\Campaigns\SaveCampaign;
use App\Actions\Programs\DeleteProgram;
use App\Actions\Programs\SaveProgram;
use App\Models\Campaign;
use App\Models\Donation;
use App\Models\Program;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @return array<string, array<int, string>>
 */
function destinationErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Se esperaba un error de validación.');
}

it('crea un programa y genera su identificador sin acentos', function (): void {
    $program = app(SaveProgram::class)->handle(null, ['name' => 'Alimentación', 'status' => 'active']);

    expect($program->slug)->toBe('alimentacion');
});

it('no repite nombres de programa sin importar mayúsculas', function (): void {
    app(SaveProgram::class)->handle(null, ['name' => 'Becas', 'status' => 'active']);

    expect(destinationErrors(fn () => app(SaveProgram::class)->handle(null, ['name' => 'BECAS', 'status' => 'active'])))
        ->toHaveKey('name');
});

it('genera identificadores únicos para campañas con el mismo nombre', function (): void {
    $first = app(SaveCampaign::class)->handle(null, ['name' => 'Navidad 2026', 'status' => 'draft']);
    $second = app(SaveCampaign::class)->handle(null, ['name' => 'Navidad 2026', 'status' => 'draft']);

    expect($first->slug)->toBe('navidad-2026')
        ->and($second->slug)->toBe('navidad-2026-2');
});

it('valida fechas y meta de la campaña', function (): void {
    $errors = destinationErrors(fn () => app(SaveCampaign::class)->handle(null, [
        'name' => 'Regreso a clases 2027', 'status' => 'draft',
        'starts_on' => '2027-08-01', 'ends_on' => '2027-06-01', 'goal_amount' => '12.345',
    ]));

    expect($errors)->toHaveKeys(['ends_on', 'goal_amount']);
});

it('guarda la meta como decimal exacto', function (): void {
    $campaign = app(SaveCampaign::class)->handle(null, ['name' => 'Campaña anual', 'status' => 'active', 'goal_amount' => '250,000.5']);

    expect($campaign->fresh()?->goal_amount)->toBe('250000.50');
});

it('no cambia de programa una campaña con donativos, ni por aplicación ni por base de datos', function (): void {
    $becas = Program::factory()->create();
    $campaign = Campaign::factory()->create(['program_id' => $becas->id]);
    Donation::factory()->create(['campaign_id' => $campaign->id]);
    $other = Program::factory()->create();

    expect(destinationErrors(fn () => app(SaveCampaign::class)->handle($campaign, [
        'name' => $campaign->name, 'status' => 'active', 'program_id' => $other->id,
    ]))['program_id'][0])->toBe(SaveCampaign::PROGRAM_LOCKED);

    expect(fn () => DB::transaction(fn () => $campaign->forceFill(['program_id' => $other->id])->save()))
        ->toThrow(QueryException::class, 'no puede cambiar de programa');
});

it('permite cambiar de programa una campaña sin donativos', function (): void {
    $campaign = Campaign::factory()->create(['program_id' => Program::factory()->create()->id]);
    $other = Program::factory()->create();

    app(SaveCampaign::class)->handle($campaign, ['name' => $campaign->name, 'status' => 'active', 'program_id' => $other->id]);

    expect($campaign->fresh()?->program_id)->toBe($other->id);
});

it('elimina campañas y programas solo si no tienen donativos ni campañas', function (): void {
    $program = Program::factory()->create();
    $campaign = Campaign::factory()->create(['program_id' => $program->id]);
    Donation::factory()->create(['campaign_id' => $campaign->id]);

    expect(destinationErrors(fn () => app(DeleteCampaign::class)->handle($campaign))['campaign'][0])->toBe(DeleteCampaign::HAS_DONATIONS)
        ->and(destinationErrors(fn () => app(DeleteProgram::class)->handle($program))['program'][0])->toBe(DeleteProgram::IN_USE)
        ->and(fn () => DB::transaction(fn () => Campaign::query()->whereKey($campaign->id)->delete()))->toThrow(QueryException::class);

    $empty = Program::factory()->create();
    app(DeleteProgram::class)->handle($empty);
    expect(Program::query()->whereKey($empty->id)->exists())->toBeFalse();
});
