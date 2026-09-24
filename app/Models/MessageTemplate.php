<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommunicationKind;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Texto editable de un tipo de correo. Sintaxis: texto simple con variables
 * {{ variable }} (lista cerrada por tipo); nunca Blade ni HTML ejecutable.
 *
 * @property int $id
 * @property CommunicationKind $kind
 * @property string $subject
 * @property string $body
 * @property int|null $updated_by_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $updatedBy
 */
class MessageTemplate extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return ['kind', 'subject', 'body', 'updated_by_id'];
    }

    public static function auditNameOnlyFields(): array
    {
        return [];
    }

    /**
     * Crea las plantillas que falten con el texto predeterminado.
     */
    public static function ensureDefaults(): void
    {
        foreach (CommunicationKind::active() as $kind) {
            self::query()->firstOrCreate(['kind' => $kind->value], [
                'subject' => $kind->defaultSubject(),
                'body' => $kind->defaultBody(),
            ]);
        }
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['kind' => CommunicationKind::class];
    }
}
