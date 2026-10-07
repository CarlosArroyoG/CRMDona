<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Interacción registrada a mano con un donante (llamada, visita, WhatsApp
 * informal…). No sustituye `Communication`, que es lo que envía el sistema
 * (docs/tecnico/gestion-relaciones-donantes.md).
 *
 * @property int $id
 * @property int $donor_id
 * @property int $assigned_to_id Responsable del seguimiento.
 * @property int $created_by_id Quién la registró.
 * @property ActivityType $type
 * @property string $subject
 * @property string|null $description
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $completed_at
 * @property string|null $result
 * @property string|null $next_action Nota libre; no crea una tarea por sí misma.
 * @property ActivityStatus $status
 * @property int|null $completed_by_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donor $donor
 * @property-read User $assignedTo
 * @property-read User $createdBy
 */
class DonorActivity extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'donor_id', 'assigned_to_id', 'type', 'status', 'scheduled_at', 'completed_at', 'completed_by_id',
            'cancelled_at', 'cancelled_by_id',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['subject', 'description', 'result', 'next_action'];
    }

    public function isScheduled(): bool
    {
        return $this->status === ActivityStatus::Scheduled;
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
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'status' => ActivityStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
