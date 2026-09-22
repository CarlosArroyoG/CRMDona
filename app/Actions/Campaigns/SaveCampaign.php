<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Actions\Concerns\NormalizesInput;
use App\Actions\Concerns\ResolvesSlugs;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Program;
use App\Rules\MoneyAmount;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;

class SaveCampaign
{
    use NormalizesInput;
    use ResolvesSlugs;

    public const string PROGRAM_LOCKED = 'La campaña ya tiene donativos: no puede cambiar de programa, para no alterar los reportes históricos.';

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function handle(?Campaign $campaign, array $input): Campaign
    {
        $input = $this->normalize($input);
        $data = Validator::make($input, [
            'program_id' => ['nullable', 'integer', Rule::exists(Program::class, 'id')],
            'name' => ['required', 'string', 'max:150'],
            'slug' => $this->slugRules(Campaign::class, $campaign?->id),
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', new Enum(CampaignStatus::class)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'goal_amount' => ['nullable', new MoneyAmount],
        ], [], [
            'program_id' => 'programa', 'name' => 'nombre', 'slug' => 'identificador', 'description' => 'descripción',
            'status' => 'estado', 'starts_on' => 'fecha de inicio', 'ends_on' => 'fecha de fin', 'goal_amount' => 'meta',
        ])->validate();

        return DB::transaction(function () use ($campaign, $data): Campaign {
            $campaign ??= new Campaign;
            $programId = isset($data['program_id']) ? (int) $data['program_id'] : null;

            if ($campaign->exists && $campaign->program_id !== $programId && $campaign->donations()->exists()) {
                throw ValidationException::withMessages(['program_id' => self::PROGRAM_LOCKED]);
            }

            $campaign->fill([
                'program_id' => $programId,
                'name' => $data['name'],
                'slug' => $this->resolveSlug(Campaign::class, $data['slug'] ?? null, $data['name'], $campaign->id),
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'goal_amount' => isset($data['goal_amount']) ? Money::normalize($data['goal_amount']) : null,
            ])->save();

            return $campaign;
        });
    }
}
