<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditEvent;
use App\Enums\AuditSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Registro de la bitácora (ADR-006). Solo inserción: la base de datos impide
 * modificarlo o eliminarlo.
 *
 * @property int $id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property AuditEvent $event
 * @property int|null $user_id
 * @property AuditSource|null $source Nulo en registros anteriores a la Fase 2.
 * @property list<string> $changed_fields
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'source' => AuditSource::class,
            'changed_fields' => 'array',
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
