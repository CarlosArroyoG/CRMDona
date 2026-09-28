<?php

declare(strict_types=1);

namespace App\Models;

use App\Communications\BulkAudience;
use App\Enums\BulkMessageStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Envío masivo informativo (docs/tecnico/carga-y-envios-masivos.md): texto
 * simple con variables y filtros de audiencia. Solo lo reciben donantes con
 * correo, no archivados y que aceptan comunicaciones; cada destinatario
 * queda en `communications` con su propio estado.
 *
 * @property int $id
 * @property string $subject
 * @property string $body
 * @property array<string, mixed> $audience Filtros (ver BulkAudience).
 * @property BulkMessageStatus $status
 * @property int|null $recipients_count Destinatarios registrados al enviar.
 * @property Carbon|null $tested_at Último correo de prueba del texto vigente.
 * @property int|null $tested_by_id
 * @property int $created_by_id
 * @property Carbon|null $sent_at
 * @property int|null $sent_by_id
 * @property Carbon|null $stopped_at
 * @property int|null $stopped_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $createdBy
 * @property-read User|null $sentBy
 * @property-read User|null $stoppedBy
 */
class BulkMessage extends Model
{
    use Auditable;

    /** Variables del texto: las comunes a todos los correos. */
    public const array VARIABLES = [
        'nombre' => 'Nombre del donante (nombre de pila, o razón social si es persona moral)',
        'organizacion' => 'Nombre de la organización',
    ];

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return ['subject', 'body', 'audience', 'status', 'recipients_count', 'tested_at', 'sent_at', 'sent_by_id', 'stopped_at', 'stopped_by_id'];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    public function isDraft(): bool
    {
        return $this->status === BulkMessageStatus::Draft;
    }

    public function isStoppable(): bool
    {
        return in_array($this->status, [BulkMessageStatus::Preparing, BulkMessageStatus::Sent], true);
    }

    public function audience(): BulkAudience
    {
        return BulkAudience::fromArray($this->audience);
    }

    /**
     * @return HasMany<Communication, $this>
     */
    public function communications(): HasMany
    {
        return $this->hasMany(Communication::class);
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
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function stoppedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stopped_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'status' => BulkMessageStatus::class,
            'tested_at' => 'datetime',
            'sent_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }
}
