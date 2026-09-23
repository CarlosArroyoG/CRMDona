<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Nota de seguimiento de una incidencia. Solo inserción (trigger).
 *
 * @property int $id
 * @property int $payment_incident_id
 * @property int $user_id
 * @property string $body
 * @property Carbon $created_at
 * @property-read User $user
 */
class PaymentIncidentNote extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public static function auditValueFields(): array
    {
        return ['payment_incident_id', 'user_id'];
    }

    public static function auditNameOnlyFields(): array
    {
        return ['body'];
    }

    /**
     * @return BelongsTo<PaymentIncident, $this>
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(PaymentIncident::class, 'payment_incident_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
