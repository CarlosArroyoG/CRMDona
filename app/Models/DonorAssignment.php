<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historial de responsables (procuradores) de un donante. Nunca se edita ni
 * se borra una fila cerrada: reasignar cierra la vigente (`ended_at`) y crea
 * una nueva (docs/tecnico/gestion-relaciones-donantes.md).
 *
 * @property int $id
 * @property int $donor_id
 * @property int $user_id Responsable.
 * @property int $assigned_by_id Quién hizo la asignación.
 * @property Carbon $started_at
 * @property Carbon|null $ended_at Nulo = vigente.
 * @property string|null $note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donor $donor
 * @property-read User $user
 * @property-read User $assignedBy
 */
class DonorAssignment extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return ['donor_id', 'user_id', 'assigned_by_id', 'started_at', 'ended_at'];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['note'];
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
