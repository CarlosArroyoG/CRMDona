<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Tarea: pendiente accionable con prioridad y fecha límite. No siempre es de
 * un donante (`donor_id` es opcional). Distinta de `DonorActivity`, que
 * registra una interacción por canal, no un pendiente con prioridad
 * (docs/tecnico/gestion-relaciones-donantes.md).
 *
 * @property int $id
 * @property int|null $donor_id
 * @property int $assigned_to_id
 * @property int $created_by_id
 * @property string $title
 * @property string|null $description
 * @property TaskPriority $priority
 * @property Carbon|null $due_date
 * @property TaskStatus $status
 * @property Carbon|null $completed_at
 * @property int|null $completed_by_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Donor|null $donor
 * @property-read User $assignedTo
 * @property-read User $createdBy
 */
class Task extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return [
            'donor_id', 'assigned_to_id', 'priority', 'status', 'due_date', 'completed_at', 'completed_by_id',
            'cancelled_at', 'cancelled_by_id',
        ];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['title', 'description'];
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [TaskStatus::Pending, TaskStatus::InProgress], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->isPast();
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
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }
}
