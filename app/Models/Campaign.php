<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Enums\ProgramStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Esfuerzo concreto de procuración (Navidad 2026…), opcionalmente de un
 * programa. Con donativos, ya no puede cambiar de programa.
 *
 * @property int $id
 * @property int|null $program_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CampaignStatus $status
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property string|null $goal_amount
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Program|null $program
 */
#[Fillable(['program_id', 'name', 'slug', 'description', 'status', 'starts_on', 'ends_on', 'goal_amount'])]
class Campaign extends Model
{
    use Auditable;

    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    public static function auditValueFields(): array
    {
        return ['program_id', 'name', 'slug', 'description', 'status', 'starts_on', 'ends_on', 'goal_amount'];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    /**
     * Puede recibir donativos nuevos en la página pública: activa, dentro de
     * sus fechas (si las tiene) y con su programa activo (si tiene programa).
     * Borrador, terminada o archivada no aceptan operaciones nuevas.
     */
    public function acceptsDonations(?CarbonInterface $today = null): bool
    {
        $today = ($today ?? now())->toDateString();

        return $this->status === CampaignStatus::Active
            && ($this->starts_on === null || $this->starts_on->toDateString() <= $today)
            && ($this->ends_on === null || $this->ends_on->toDateString() >= $today)
            && ($this->program === null || $this->program->status === ProgramStatus::Active);
    }

    /**
     * @return BelongsTo<Program, $this>
     */
    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * @return HasMany<Donation, $this>
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'goal_amount' => 'decimal:2',
        ];
    }
}
