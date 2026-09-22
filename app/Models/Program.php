<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProgramStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Destino permanente de los donativos (Becas, Alimentación…).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProgramStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'status'])]
class Program extends Model
{
    use Auditable;

    /** @use HasFactory<ProgramFactory> */
    use HasFactory;

    public static function auditValueFields(): array
    {
        return ['name', 'slug', 'description', 'status'];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    /**
     * @return HasMany<Campaign, $this>
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * Donativos asignados directamente al programa (sin campaña).
     *
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
            'status' => ProgramStatus::class,
        ];
    }
}
